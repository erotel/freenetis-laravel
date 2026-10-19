<?php

namespace Tests\Characterization\Members;

use App\Models\Setting;
use App\Models\User;
use App\Services\AclService;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Charakterizace přerušení členství (MembershipInterruptController) před
 * přesunem do domény Members: založení (poplatek special_type 1 jako
 * members_fees + membership_interrupts, volitelně konec členství po
 * přerušení → leaving_date), validace (pořadí dat, min/max měsíců, překryv),
 * úprava, smazání, audit a ACL.
 */
class MembershipInterruptTest extends DatabaseTestCase
{
    private int $adminId;
    private int $feeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = (int) DB::table('users')->where('type', 1)->value('id');
        $this->feeId   = (int) DB::table('fees')->where('special_type_id', 1)->value('id');
        if (!$this->feeId) {
            $this->markTestSkipped('chybí poplatek přerušení (special_type_id 1)');
        }
        Setting::set('membership_interrupt_minimum', 0);
        Setting::set('membership_interrupt_maximum', 0);
        $this->acl();
    }

    private function acl(array $deny = []): void
    {
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturnUsing(fn ($u, $aco, $section, $value) => !in_array("{$aco}/{$section}/{$value}", $deny, true));
        $acl->shouldReceive('flushUserCache')->andReturnNull();
    }

    private function as()
    {
        return $this->actingAs(User::find($this->adminId));
    }

    private function member(string $leaving = '9999-12-31'): int
    {
        return (int) DB::table('members')->insertGetId(['name' => 'Char Int', 'type' => 2, 'registration' => 1, 'locked' => 0,
            'address_point_id' => (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id'),
            'entrance_date' => '2020-01-01', 'leaving_date' => $leaving]);
    }

    private function interrupts(int $member): array
    {
        return DB::table('membership_interrupts as mi')->join('members_fees as mf', 'mf.id', '=', 'mi.members_fee_id')
            ->where('mi.member_id', $member)->orderBy('mi.id')
            ->get(['mi.id', 'mi.comment', 'mi.end_after_interrupt_end', 'mf.fee_id', 'mf.member_id', 'mf.activation_date', 'mf.deactivation_date', 'mf.priority', 'mf.comment as fee_comment'])
            ->map(fn ($r) => (array) $r)->all();
    }

    /**
     * Datum z ukázkového kalendáře (listopad 2026 = příští měsíc) posunuté tak,
     * aby test zůstal „v budoucnosti"; poslední den měsíce zůstává posledním.
     */
    private function d(string $ymd): string
    {
        $c      = \Carbon\Carbon::parse($ymd);
        $shift  = (int) \Carbon\Carbon::parse('2026-11-01')->diffInMonths(now()->startOfMonth()->addMonth());
        $target = $c->copy()->startOfMonth()->addMonths($shift);
        return $target->day($c->day === $c->daysInMonth ? $target->daysInMonth : $c->day)->toDateString();
    }

    private function leaving(int $member): string
    {
        return (string) DB::table('members')->where('id', $member)->value('leaving_date');
    }

    private function lastAudit(): object
    {
        return DB::table('audit_logs')->where('auditable_type', 'membership_interrupts')->orderByDesc('id')->first();
    }

    // ── založení ─────────────────────────────────────────────────────────────

    public function test_zalozeni(): void
    {
        $m = $this->member();
        $this->as()->get(route('membership-interrupts.create', $m))->assertOk()->assertViewIs('membership_interrupts.form')
            ->assertViewHas('action', 'create')->assertViewHas('record', null)->assertViewHas('member', fn ($x) => (int) $x->id === $m);
        $this->as()->get(route('membership-interrupts.create', 999999999))->assertNotFound();

        $this->as()->post(route('membership-interrupts.store', $m), ['from' => $this->d('2026-11-01'), 'to' => $this->d('2027-02-28'), 'comment' => '  cesta  '])
            ->assertRedirect(route('members.show', $m))->assertSessionHas('success', 'Přerušení členství bylo přidáno.');
        $rows = $this->interrupts($m);
        $this->assertCount(1, $rows);
        $this->assertSame(['cesta', 0, $this->feeId, $m, $this->d('2026-11-01'), $this->d('2027-02-28'), 0, ''], [$rows[0]['comment'], (int) $rows[0]['end_after_interrupt_end'],
            (int) $rows[0]['fee_id'], (int) $rows[0]['member_id'], $rows[0]['activation_date'], $rows[0]['deactivation_date'], (int) $rows[0]['priority'], $rows[0]['fee_comment']]);
        $this->assertSame('9999-12-31', $this->leaving($m));
        $a = $this->lastAudit();
        $this->assertSame(['created', $m, ['from' => $this->d('2026-11-01'), 'to' => $this->d('2027-02-28'), 'end_after' => false]], [$a->action, (int) $a->auditable_id, json_decode($a->new_values, true)]);

        $m2 = $this->member();
        $this->as()->post(route('membership-interrupts.store', $m2), ['from' => $this->d('2026-11-01'), 'to' => $this->d('2027-01-31'), 'comment' => 'konec', 'end_after_interrupt_end' => '1']);
        $this->assertSame($this->d('2027-01-31'), $this->leaving($m2), 'konec po přerušení');
        $this->assertSame(1, (int) $this->interrupts($m2)[0]['end_after_interrupt_end']);
    }

    public function test_validace(): void
    {
        $m = $this->member();
        $post = fn (array $d) => $this->as()->from(route('membership-interrupts.create', $m))->post(route('membership-interrupts.store', $m), $d + ['comment' => 'c']);

        $post(['from' => '', 'to' => 'x', 'comment' => ''])->assertSessionHasErrors(['from', 'to', 'comment']);
        $post(['from' => $this->d('2027-01-01'), 'to' => $this->d('2026-12-01')])->assertRedirect(route('membership-interrupts.create', $m))
            ->assertSessionHasErrors(['from' => 'Datum od musí být menší než datum do.']);

        Setting::set('membership_interrupt_minimum', 3);
        $post(['from' => $this->d('2026-11-01'), 'to' => $this->d('2027-01-15')])->assertSessionHasErrors(['from' => 'Minimální délka přerušení je 3 měsíců.']);
        Setting::set('membership_interrupt_minimum', 0);
        Setting::set('membership_interrupt_maximum', 6);
        $post(['from' => $this->d('2026-11-01'), 'to' => $this->d('2027-06-01')])->assertSessionHasErrors(['from' => 'Maximální délka přerušení je 6 měsíců.']);
        Setting::set('membership_interrupt_maximum', 0);

        $post(['from' => $this->d('2026-11-01'), 'to' => $this->d('2027-01-31')])->assertSessionHasNoErrors();
        $post(['from' => $this->d('2027-01-31'), 'to' => $this->d('2027-03-31')])->assertSessionHasErrors(['from' => 'Interval přerušení se překrývá s jiným přerušením tohoto člena.']);
        $post(['from' => $this->d('2027-02-01'), 'to' => $this->d('2027-03-31')])->assertSessionHasNoErrors();
        $this->assertCount(2, $this->interrupts($m));
    }

    // ── úprava a smazání ─────────────────────────────────────────────────────

    public function test_uprava(): void
    {
        $m = $this->member();
        $this->as()->post(route('membership-interrupts.store', $m), ['from' => $this->d('2026-11-01'), 'to' => $this->d('2027-01-31'), 'comment' => 'a', 'end_after_interrupt_end' => '1']);
        $this->as()->post(route('membership-interrupts.store', $m), ['from' => $this->d('2027-03-01'), 'to' => $this->d('2027-04-30'), 'comment' => 'b']);
        [$first, $second] = $this->interrupts($m);

        $this->as()->get(route('membership-interrupts.edit', $first['id']))->assertOk()->assertViewHas('action', 'edit')
            ->assertViewHas('record', fn ($r) => (int) $r->id === (int) $first['id'] && $r->activation_date === $this->d('2026-11-01'));
        $this->as()->get(route('membership-interrupts.edit', 999999999))->assertNotFound();

        // vlastní interval se nepočítá jako překryv; posunutí konce přepíše leaving_date
        $this->as()->put(route('membership-interrupts.update', $first['id']), ['from' => $this->d('2026-11-01'), 'to' => $this->d('2027-02-15'), 'comment' => ' a2 ', 'end_after_interrupt_end' => '1'])
            ->assertRedirect(route('members.show', $m))->assertSessionHas('success', 'Přerušení členství bylo upraveno.');
        $this->assertSame(['a2', $this->d('2027-02-15'), $this->d('2027-02-15')], [$this->interrupts($m)[0]['comment'], $this->interrupts($m)[0]['deactivation_date'], $this->leaving($m)]);
        $a = $this->lastAudit();
        $this->assertSame(['updated', $m, ['interrupt_id' => (int) $first['id'], 'from' => $this->d('2026-11-01'), 'to' => $this->d('2027-02-15'), 'end_after' => true]],
            [$a->action, (int) $a->auditable_id, json_decode($a->new_values, true)]);

        $this->as()->from(route('membership-interrupts.edit', $first['id']))->put(route('membership-interrupts.update', $first['id']), ['from' => $this->d('2026-11-01'), 'to' => $this->d('2027-03-15'), 'comment' => 'x'])
            ->assertSessionHasErrors(['from' => 'Interval přerušení se překrývá s jiným přerušením tohoto člena.']);

        // minulé leaving_date se při end_after nepřepíše
        $past = $this->member('2020-06-30');
        $this->as()->post(route('membership-interrupts.store', $past), ['from' => $this->d('2026-11-01'), 'to' => $this->d('2027-01-31'), 'comment' => 'p']);
        $this->as()->put(route('membership-interrupts.update', $this->interrupts($past)[0]['id']), ['from' => $this->d('2026-11-01'), 'to' => $this->d('2027-01-31'), 'comment' => 'p', 'end_after_interrupt_end' => '1']);
        $this->assertSame('2020-06-30', $this->leaving($past));
    }

    /**
     * Bez „konce po přerušení" se vrací jen odchod nastavený tímto přerušením
     * (dřív se nuloval jakýkoli budoucí → NOT NULL výjimka).
     */
    public function test_uprava_bez_konce_vraci_jen_vlastni_odchod(): void
    {
        $m = $this->member();
        $this->as()->post(route('membership-interrupts.store', $m), ['from' => $this->d('2026-11-01'), 'to' => $this->d('2027-01-31'), 'comment' => 'a']);
        $id = $this->interrupts($m)[0]['id'];
        $this->as()->put(route('membership-interrupts.update', $id), ['from' => $this->d('2026-11-01'), 'to' => $this->d('2027-02-28'), 'comment' => 'b'])
            ->assertSessionHas('success', 'Přerušení členství bylo upraveno.');
        $this->assertSame(['9999-12-31', 'b'], [$this->leaving($m), $this->interrupts($m)[0]['comment']], 'běžný člen beze změny');

        $this->as()->put(route('membership-interrupts.update', $id), ['from' => $this->d('2026-11-01'), 'to' => $this->d('2027-02-28'), 'comment' => 'c', 'end_after_interrupt_end' => '1']);
        $this->assertSame($this->d('2027-02-28'), $this->leaving($m));
        $this->as()->put(route('membership-interrupts.update', $id), ['from' => $this->d('2026-11-01'), 'to' => $this->d('2027-02-28'), 'comment' => 'd']);
        $this->assertSame('9999-12-31', $this->leaving($m), 'vypnutí konce vrátí vlastní odchod');

        // jiný budoucí odchod (výpověď) zůstává
        $notice = $this->member($this->d('2027-06-30'));
        $this->as()->post(route('membership-interrupts.store', $notice), ['from' => $this->d('2026-11-01'), 'to' => $this->d('2027-01-31'), 'comment' => 'n']);
        $this->as()->put(route('membership-interrupts.update', $this->interrupts($notice)[0]['id']), ['from' => $this->d('2026-11-01'), 'to' => $this->d('2027-02-28'), 'comment' => 'n2']);
        $this->assertSame($this->d('2027-06-30'), $this->leaving($notice));
    }

    public function test_smazani_s_koncem_vrati_odchod(): void
    {
        $m = $this->member();
        $this->as()->post(route('membership-interrupts.store', $m), ['from' => $this->d('2026-11-01'), 'to' => $this->d('2027-01-31'), 'comment' => 'a', 'end_after_interrupt_end' => '1']);
        $this->assertSame($this->d('2027-01-31'), $this->leaving($m));
        $this->as()->delete(route('membership-interrupts.destroy', $this->interrupts($m)[0]['id']))->assertSessionHas('success', 'Přerušení členství bylo smazáno.');
        $this->assertSame('9999-12-31', $this->leaving($m));

        // odchod mezitím změněný jinak (výpověď) smazání nepřepíše
        $n = $this->member();
        $this->as()->post(route('membership-interrupts.store', $n), ['from' => $this->d('2026-11-01'), 'to' => $this->d('2027-01-31'), 'comment' => 'a', 'end_after_interrupt_end' => '1']);
        DB::table('members')->where('id', $n)->update(['leaving_date' => $this->d('2026-12-31')]);
        $this->as()->delete(route('membership-interrupts.destroy', $this->interrupts($n)[0]['id']));
        $this->assertSame($this->d('2026-12-31'), $this->leaving($n));
    }

    public function test_smazani(): void
    {
        $m = $this->member();
        $this->as()->post(route('membership-interrupts.store', $m), ['from' => $this->d('2026-11-01'), 'to' => $this->d('2027-01-31'), 'comment' => 'a']);
        $row = $this->interrupts($m)[0];

        $this->as()->delete(route('membership-interrupts.destroy', $row['id']))
            ->assertRedirect(route('members.show', $m))->assertSessionHas('success', 'Přerušení členství bylo smazáno.');
        $this->assertSame([], $this->interrupts($m));
        $this->assertFalse(DB::table('members_fees')->where('member_id', $m)->where('fee_id', $this->feeId)->exists());
        $this->assertSame('9999-12-31', $this->leaving($m), 'bez konce po přerušení se leaving_date nesahá');
        $a = $this->lastAudit();
        $this->assertSame(['deleted', $m, ['interrupt_id' => (int) $row['id']]], [$a->action, (int) $a->auditable_id, json_decode($a->old_values, true)]);
        $this->as()->delete(route('membership-interrupts.destroy', 999999999))->assertNotFound();
    }

    // ── ACL ──────────────────────────────────────────────────────────────────

    public function test_prava(): void
    {
        $m = $this->member();
        $this->as()->post(route('membership-interrupts.store', $m), ['from' => $this->d('2026-11-01'), 'to' => $this->d('2027-01-31'), 'comment' => 'a']);
        $id = $this->interrupts($m)[0]['id'];

        $this->acl(['new_all/Members_Controller/membership_interrupts', 'edit_all/Members_Controller/membership_interrupts', 'delete_all/Members_Controller/membership_interrupts']);
        $this->as()->get(route('membership-interrupts.create', $m))->assertForbidden();
        $this->as()->post(route('membership-interrupts.store', $m), ['from' => $this->d('2027-06-01'), 'to' => $this->d('2027-07-31'), 'comment' => 'x'])->assertForbidden();
        $this->as()->get(route('membership-interrupts.edit', $id))->assertForbidden();
        $this->as()->put(route('membership-interrupts.update', $id), ['from' => $this->d('2026-11-01'), 'to' => $this->d('2027-01-31'), 'comment' => 'x'])->assertForbidden();
        $this->as()->delete(route('membership-interrupts.destroy', $id))->assertForbidden();
        $this->assertCount(1, $this->interrupts($m));
        $this->as()->get(route('membership-interrupts.edit', 999999999))->assertNotFound('neexistující → 404 dřív než ACL');
    }
}
