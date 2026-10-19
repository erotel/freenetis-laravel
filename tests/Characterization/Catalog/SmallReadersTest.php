<?php

namespace Tests\Characterization\Catalog;

use Illuminate\Support\Facades\DB;

/**
 * Charakterizace drobných přehledů před přesunem do domén: 1:1 NAT veřejných
 * IP (PublicIpNat1to1Controller), audit log (AuditLogController), fronta
 * vratek pro Pohodu (PohodaRefundQueueController), anomálie line-id
 * (LineIdAnomalyController).
 */
class SmallReadersTest extends CatalogTestCase
{
    private function memberOf(int $userId): int
    {
        return (int) DB::table('users')->where('id', $userId)->value('member_id');
    }

    public function test_nat(): void
    {
        $pub = '198.51.100.' . random_int(1, 250);
        $id = (int) DB::table('public_ip_nat_1to1')->insertGetId(['public_ip' => $pub, 'private_ip' => '10.244.1.5', 'enabled' => 1, 'comment' => "Char {$this->tag}"]);
        $off = (int) DB::table('public_ip_nat_1to1')->insertGetId(['public_ip' => '198.51.100.251', 'private_ip' => null, 'enabled' => 0]);

        $rows = $this->as()->get(route('public-ip-nat.index', ['q' => '10.244.1.5']))->assertOk()->assertViewHas('canEdit', true)->viewData('rows');
        $this->assertSame([$id], $rows->pluck('id')->map(fn ($i) => (int) $i)->all());
        $this->assertNotContains($off, $this->as()->get(route('public-ip-nat.index', ['enabled' => '1']))->viewData('rows')->pluck('id')->map(fn ($i) => (int) $i)->all());

        $this->as()->get(route('public-ip-nat.edit', $id))->assertOk()->assertViewHas('record', fn ($r) => (int) $r->id === $id);
        $this->as()->put(route('public-ip-nat.update', $id), ['private_ip' => '10.244.1.6', 'enabled' => '1'])
            ->assertRedirect(route('public-ip-nat.index'))->assertSessionHas('success', 'Záznam byl uložen.');
        $r = DB::table('public_ip_nat_1to1')->where('id', $id)->first();
        $this->assertSame(['10.244.1.6', 1, $this->memberOf($this->adminId)], [$r->private_ip, (int) $r->enabled, (int) $r->modified_by]);
        $this->as()->put(route('public-ip-nat.update', $id), ['private_ip' => $pub])->assertSessionHasErrors(['private_ip' => 'Veřejná a privátní IP nesmějí být stejné.']);
        foreach (['x', '2a07:9c0::1'] as $bad) {
            $this->as()->put(route('public-ip-nat.update', $id), ['private_ip' => $bad])->assertSessionHasErrors(['private_ip' => 'Neplatná privátní IPv4 adresa.']);
        }
        $this->as()->put(route('public-ip-nat.update', $id), [])->assertSessionHasNoErrors();
        $this->assertSame([null, 0], [DB::table('public_ip_nat_1to1')->where('id', $id)->value('private_ip'), (int) DB::table('public_ip_nat_1to1')->where('id', $id)->value('enabled')]);

        DB::table('public_ip_nat_1to1')->where('id', $id)->update(['private_ip' => '10.244.1.7']);
        $this->as()->get("/public-ip-nat/{$id}/clear")->assertStatus(405);   // dřív GET bez CSRF
        $this->assertSame('10.244.1.7', DB::table('public_ip_nat_1to1')->where('id', $id)->value('private_ip'));
        $this->as()->post(route('public-ip-nat.clear', $id))->assertRedirect(route('public-ip-nat.index'))->assertSessionHas('success', 'Mapování bylo vymazáno.');
        $this->assertNull(DB::table('public_ip_nat_1to1')->where('id', $id)->value('private_ip'));
        $this->as()->get(route('public-ip-nat.edit', 999999999))->assertNotFound();

        $this->acl(['edit_all/Network_Controller/public_ip_nat']);
        $this->as()->get(route('public-ip-nat.edit', $id))->assertForbidden();
        $this->as()->put(route('public-ip-nat.update', $id), [])->assertForbidden();
        $this->as()->post(route('public-ip-nat.clear', $id))->assertForbidden();
        $this->acl(['view_all/Network_Controller/public_ip_nat']);
        $this->as()->get(route('public-ip-nat.index'))->assertForbidden();
    }

    public function test_audit_log(): void
    {
        $a = (int) DB::table('audit_logs')->insertGetId(['auditable_type' => "char_{$this->tag}", 'auditable_id' => 7, 'action' => 'deleted', 'user_id' => $this->adminId,
            'old_values' => json_encode(['ip' => "10.20.{$this->tag}"]), 'occurred_at' => '2099-01-02 10:00:00']);
        $b = (int) DB::table('audit_logs')->insertGetId(['auditable_type' => "char_{$this->tag}", 'auditable_id' => 8, 'action' => 'created', 'user_id' => $this->adminId,
            'new_values' => '{}', 'occurred_at' => '2099-01-05 10:00:00']);
        $ids = fn (array $q) => collect($this->as()->get(route('audit.index', $q))->assertOk()->viewData('logs')->items())->pluck('id')->map(fn ($i) => (int) $i)->all();

        $this->assertSame([$b, $a], $ids(['type' => "char_{$this->tag}"]));
        $this->assertSame([$a], $ids(['q' => "10.20.{$this->tag}"]));
        $this->assertSame([$a], $ids(['type' => "char_{$this->tag}", 'action' => 'deleted']));
        $this->assertSame([$a], $ids(['type' => "char_{$this->tag}", 'to' => '2099-01-03']));
        $this->assertSame([$b], $ids(['type' => "char_{$this->tag}", 'from' => '2099-01-03']));
        $login = DB::table('users')->where('id', $this->adminId)->value('login');
        $this->assertSame([$b, $a], $ids(['type' => "char_{$this->tag}", 'user' => $login]));
        $this->assertSame([$b, $a], $ids(['type' => "char_{$this->tag}", 'user' => (string) $this->adminId]));
        $res = $this->as()->get(route('audit.index', ['type' => "char_{$this->tag}"]));
        $this->assertContains("char_{$this->tag}", $res->viewData('types')->all());
        $this->assertSame($login, collect($res->viewData('logs')->items())->first()->actor_login);

        $this->acl(['view_all/Aro_groups_Controller/aro_group']);
        $this->as()->get(route('audit.index'))->assertForbidden();
    }

    public function test_pohoda_vratky(): void
    {
        $m = (int) DB::table('members')->where('id', '>', 1)->value('id');
        $row = fn (string $status) => (int) DB::table('pohoda_refund_queue')->insertGetId(['member_id' => $m, 'member_type' => 2, 'doc_number' => 'V' . random_int(1000, 9999),
            'refund_account' => '1/0100', 'amount' => 10, 'created_at' => now(), 'status' => $status]);
        $new = $row('new');
        $exp = $row('exported');
        $ids = fn (array $q) => collect($this->as()->get(route('pohoda-refund-queue.index', $q))->assertOk()->viewData('items')->items())->pluck('id')->map(fn ($i) => (int) $i)->all();
        $this->assertSame([$exp, $new], array_slice($ids([]), 0, 2));
        $this->assertContains($new, $ids(['status' => 'new']));
        $this->assertNotContains($exp, $ids(['status' => 'new']));
        $this->acl(['view_all/Accounts_Controller/invoices']);
        $this->as()->get(route('pohoda-refund-queue.index'))->assertForbidden();
    }

    public function test_anomalie_line_id(): void
    {
        $mk = fn (string $sev, ?string $resolved, string $last) => (int) DB::table('line_id_anomalies')->insertGetId(['circuit_id' => "char {$this->tag} {$sev}", 'seen_mac' => 'AA:00:00:00:00:01',
            'type' => 'mac_change', 'severity' => $sev, 'last_seen' => $last, 'resolved_at' => $resolved]);
        DB::table('line_id_anomalies')->update(['resolved_at' => now()]);
        $warn = $mk('warning', null, '2099-01-03 00:00:00');
        $crit = $mk('critical', null, '2099-01-01 00:00:00');
        $done = $mk('high', '2099-01-01 00:00:00', '2099-01-02 00:00:00');

        $res = $this->as()->get(route('line_id_anomalies.index'))->assertOk();
        $this->assertSame([$crit, $warn], collect($res->viewData('items')->items())->pluck('id')->map(fn ($i) => (int) $i)->all());
        $this->assertSame([1, true], [$res->viewData('openCritical'), $res->viewData('canResolve')]);
        $this->assertSame([$crit, $done, $warn], collect($this->as()->get(route('line_id_anomalies.index', ['resolved' => 1]))->viewData('items')->items())->pluck('id')->map(fn ($i) => (int) $i)->all());
        $this->assertSame([$warn], collect($this->as()->get(route('line_id_anomalies.index', ['severity' => 'warning']))->viewData('items')->items())->pluck('id')->map(fn ($i) => (int) $i)->all());

        $this->as()->from(route('line_id_anomalies.index'))->post(route('line_id_anomalies.resolve', $crit))->assertRedirect(route('line_id_anomalies.index'))->assertSessionHas('success', 'Anomálie označena jako vyřešená.');
        $this->assertNotNull(DB::table('line_id_anomalies')->where('id', $crit)->value('resolved_at'));

        $this->acl(['edit_all/Subnets_Controller/dhcp']);
        $this->as()->post(route('line_id_anomalies.resolve', $warn))->assertForbidden();
        $this->acl(['view_all/Subnets_Controller/dhcp']);
        $this->as()->get(route('line_id_anomalies.index'))->assertForbidden();
    }
}
