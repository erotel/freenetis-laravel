<?php

namespace Tests\Feature\Audit;

use App\Models\AllowedSubnet;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Audit trail (NIS2/ZoKB): zápis přes AuditLogger, redakce citlivých polí,
 * přeskočení prázdných změn / vypnutého auditu / ignorovaných tabulek a
 * automatický audit přes Auditable trait na Eloquent modelu.
 */
class AuditLoggerTest extends DatabaseTestCase
{
    private function lastFor(string $table, int $objectId): ?object
    {
        return DB::table('audit_logs')
            ->where('auditable_type', $table)
            ->where('auditable_id', $objectId)
            ->orderByDesc('id')
            ->first();
    }

    public function test_zapise_radek_se_spravnymi_poli(): void
    {
        AuditLogger::log('updated', 'members', 987654,
            ['speed_class_id' => 2],
            ['speed_class_id' => 5]);

        $row = $this->lastFor('members', 987654);
        $this->assertNotNull($row);
        $this->assertSame('updated', $row->action);
        $this->assertSame('members', $row->auditable_type);
        $this->assertSame(['speed_class_id' => 2], json_decode($row->old_values, true));
        $this->assertSame(['speed_class_id' => 5], json_decode($row->new_values, true));
    }

    public function test_rediguje_citliva_pole(): void
    {
        AuditLogger::log('updated', 'users', 987655,
            ['password' => 'stare'],
            ['password' => 'nove', 'login' => 'admin']);

        $row = $this->lastFor('users', 987655);
        $new = json_decode($row->new_values, true);
        $old = json_decode($row->old_values, true);
        $this->assertSame('***', $new['password']);
        $this->assertSame('***', $old['password']);
        $this->assertSame('admin', $new['login']); // necitlivé pole zůstává
    }

    public function test_preskoci_prazdnou_zmenu(): void
    {
        $before = DB::table('audit_logs')->count();
        AuditLogger::log('updated', 'members', 987656, [], []);
        $this->assertSame($before, DB::table('audit_logs')->count());
    }

    public function test_preskoci_kdyz_je_audit_vypnuty(): void
    {
        config()->set('audit.enabled', false);
        AuditLogger::log('created', 'members', 987657, null, ['x' => 1]);
        config()->set('audit.enabled', true);

        $this->assertNull($this->lastFor('members', 987657));
    }

    public function test_preskoci_ignorovanou_tabulku(): void
    {
        AuditLogger::log('created', 'sessions', 987658, null, ['x' => 1]);
        $this->assertNull($this->lastFor('sessions', 987658));
    }

    public function test_trait_zaznamena_create_update_delete(): void
    {
        $member  = (int) DB::table('members')->where('id', '>', 1)->orderBy('id')->value('id');
        $subnetId = DB::table('subnets')->insertGetId(['name' => 'NET audit-test']);

        // CREATE
        $as = AllowedSubnet::create([
            'member_id' => $member, 'subnet_id' => $subnetId,
            'speed_class_id' => null, 'charged' => 0, 'enabled' => 1,
        ]);
        $created = $this->lastFor('allowed_subnets', $as->id);
        $this->assertNotNull($created);
        $this->assertSame('created', $created->action);

        // UPDATE
        $as->update(['charged' => 1]);
        $updated = $this->lastFor('allowed_subnets', $as->id);
        $this->assertSame('updated', $updated->action);
        $new = json_decode($updated->new_values, true);
        $this->assertArrayHasKey('charged', $new);

        // DELETE
        $id = $as->id;
        $as->delete();
        $deleted = $this->lastFor('allowed_subnets', $id);
        $this->assertSame('deleted', $deleted->action);
    }

    public function test_udrzba_partitions_dry_run_probehne(): void
    {
        $code = Artisan::call('audit:maintain-partitions', ['--dry-run' => true]);
        $this->assertSame(0, $code);
    }
}
