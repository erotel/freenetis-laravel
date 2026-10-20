<?php

namespace Tests\Characterization\Notifications;

use App\Domains\Acl\AclService;
use App\Http\Controllers\RedirectController;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Charakterizace ruční správy přesměrování (RedirectController) před
 * přesunem do domény Notifications: seznam s filtry, aktivace členovi (všechny
 * IP — přímé i přes zařízení) a jedné IP (smaže + vloží s autorem a
 * komentářem), nabídka zpráv (bez systémových typů a prázdného textu),
 * zrušení (IP / všechny IP člena), přesměrování na kartě člena, audit, ACL.
 */
class ManualRedirectsTest extends DatabaseTestCase
{
    private int $adminId;
    private string $tag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = (int) DB::table('users')->where('type', 1)->value('id');
        $this->tag = 'Rq' . substr(md5(uniqid('', true)), 0, 8);
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

    /** @return array{member:int, device_ip:int, direct_ip:int} */
    private function member(): array
    {
        $id = (int) DB::table('members')->insertGetId(['name' => "Char {$this->tag}", 'type' => 2, 'registration' => 1, 'locked' => 0,
            'address_point_id' => (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id'),
            'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31']);
        $uid = (int) DB::table('users')->insertGetId(['member_id' => $id, 'login' => 'charred_' . uniqid(), 'password' => 'x', 'type' => 1,
            'application_password' => 'x', 'settings' => '', 'name' => 'C', 'surname' => 'R', 'comment' => '']);
        $iface = (int) DB::table('ifaces')->insertGetId(['type' => 1, 'name' => 'e',
            'device_id' => DB::table('devices')->insertGetId(['name' => 'char-r', 'type' => 1, 'user_id' => $uid])]);
        return [
            'member'    => $id,
            'device_ip' => (int) DB::table('ip_addresses')->insertGetId(['iface_id' => $iface, 'ip_address' => '10.254.' . random_int(1, 250) . '.' . random_int(1, 250)]),
            'direct_ip' => (int) DB::table('ip_addresses')->insertGetId(['member_id' => $id, 'ip_address' => '10.254.' . random_int(1, 250) . '.' . random_int(1, 250)]),
        ];
    }

    private function message(int $type = 0, string $text = 'text'): int
    {
        return (int) DB::table('messages')->insertGetId(['name' => "Char zpráva {$this->tag} {$type}", 'text' => $text, 'type' => $type, 'self_cancel' => 0, 'ignore_whitelist' => 0]);
    }

    private function redirects(int $msg): array
    {
        return DB::table('messages_ip_addresses')->where('message_id', $msg)->orderBy('ip_address_id')
            ->get(['ip_address_id', 'user_id', 'comment'])->map(fn ($r) => [(int) $r->ip_address_id, (int) $r->user_id, $r->comment])->all();
    }

    private function lastAudit(): object
    {
        return DB::table('audit_logs')->where('auditable_type', 'messages_ip_addresses')->orderByDesc('id')->first();
    }

    // ── aktivace ─────────────────────────────────────────────────────────────

    public function test_aktivace_clenovi_vsechny_ip(): void
    {
        $x = $this->member();
        $msg = $this->message();
        $sys = $this->message(4);
        $empty = $this->message(0, '   ');

        $form = $this->as()->get(route('redirects.activate-member', $x['member']))->assertOk()->assertViewIs('redirects.activate');
        $offered = $form->viewData('messages');
        $this->assertArrayHasKey($msg, $offered);
        $this->assertArrayNotHasKey($sys, $offered, 'systémový typ ne');
        $this->assertArrayNotHasKey($empty, $offered, 'prázdný text ne');
        $this->assertSame(['member', null, route('redirects.activate-member', $x['member']), route('members.show', $x['member'])],
            [$form->viewData('target'), $form->viewData('ip'), $form->viewData('formUrl'), $form->viewData('backUrl')]);
        $this->assertSame(DB::table('messages')->whereIn('id', array_keys($offered))->orderBy('type')->orderBy('name')->pluck('id')->map(fn ($i) => (int) $i)->all(),
            array_keys($offered), 'podle typu a jména');

        DB::table('messages_ip_addresses')->insert(['message_id' => $msg, 'ip_address_id' => $x['device_ip'], 'user_id' => 1, 'comment' => 'staré', 'datetime' => '2020-01-01']);
        $this->as()->post(route('redirects.activate-member', $x['member']), ['message_id' => $msg, 'comment' => 'ruční'])
            ->assertRedirect(route('members.show', $x['member']))->assertSessionHas('success', 'Přesměrování bylo aktivováno pro všechny IP adresy člena.');
        $this->assertEqualsCanonicalizing([[$x['device_ip'], $this->adminId, 'ruční'], [$x['direct_ip'], $this->adminId, 'ruční']], $this->redirects($msg));
        $a = $this->lastAudit();
        $this->assertSame(['created', $x['member'], ['message_id' => $msg, 'ip_count' => 2, 'scope' => 'member']], [$a->action, (int) $a->auditable_id, json_decode($a->new_values, true)]);

        // zpráva mimo nabídku (systémový typ, prázdný text) neprojde ani podvrženým POSTem
        $this->as()->post(route('redirects.activate-member', $x['member']), ['message_id' => $sys])->assertSessionHasErrors('message_id');
        $this->as()->post(route('redirects.activate-member', $x['member']), ['message_id' => $empty])->assertSessionHasErrors('message_id');
        $this->as()->post(route('redirects.activate-ip', $x['device_ip']), ['message_id' => $sys])->assertSessionHasErrors('message_id');
        $this->assertSame([], $this->redirects($sys));

        $this->as()->post(route('redirects.activate-member', $x['member']), ['message_id' => 999999999])->assertSessionHasErrors('message_id');
        $this->as()->get(route('redirects.activate-member', 999999999))->assertNotFound();

        $bare = (int) DB::table('members')->insertGetId(['name' => 'Char bez IP', 'type' => 2, 'registration' => 1, 'locked' => 0,
            'address_point_id' => (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id'), 'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31']);
        $this->as()->post(route('redirects.activate-member', $bare), ['message_id' => $msg])
            ->assertRedirect(route('members.show', $bare))->assertSessionHas('warning', 'Člen nemá žádné IP adresy.');
    }

    public function test_aktivace_jedne_ip(): void
    {
        $x = $this->member();
        $msg = $this->message();

        $form = $this->as()->get(route('redirects.activate-ip', $x['device_ip']))->assertOk();
        $this->assertSame(['ip', null, route('ip_addresses.show', $x['device_ip'])], [$form->viewData('target'), $form->viewData('member'), $form->viewData('backUrl')]);
        $this->assertSame($x['device_ip'], (int) $form->viewData('ip')->id);

        $this->as()->post(route('redirects.activate-ip', $x['device_ip']), ['message_id' => $msg])
            ->assertRedirect(route('ip_addresses.show', $x['device_ip']))->assertSessionHas('success', 'Přesměrování bylo aktivováno.');
        $this->assertSame([[$x['device_ip'], $this->adminId, null]], $this->redirects($msg));
        $a = $this->lastAudit();
        $this->assertSame(['created', $x['device_ip'], ['message_id' => $msg, 'scope' => 'ip']], [$a->action, (int) $a->auditable_id, json_decode($a->new_values, true)]);
        $this->as()->get(route('redirects.activate-ip', 999999999))->assertNotFound();
    }

    // ── zrušení ──────────────────────────────────────────────────────────────

    public function test_zruseni(): void
    {
        $x = $this->member();
        $msg = $this->message();
        $other = $this->message();
        foreach ([$msg, $other] as $m) {
            foreach ([$x['device_ip'], $x['direct_ip']] as $ip) {
                DB::table('messages_ip_addresses')->insert(['message_id' => $m, 'ip_address_id' => $ip, 'datetime' => now()]);
            }
        }

        $this->as()->from(route('redirects.index'))->delete(route('redirects.delete', [$x['device_ip'], $msg]))
            ->assertRedirect(route('redirects.index'))->assertSessionHas('success', 'Přesměrování bylo zrušeno.');
        $this->assertSame([$x['direct_ip']], array_column($this->redirects($msg), 0));
        $a = $this->lastAudit();
        $this->assertSame(['deleted', $x['device_ip'], ['message_id' => $msg, 'scope' => 'ip']], [$a->action, (int) $a->auditable_id, json_decode($a->old_values, true)]);

        $this->as()->delete(route('redirects.delete-member', [$x['member'], $other]))
            ->assertRedirect(route('members.show', $x['member']))->assertSessionHas('success', 'Přesměrování bylo zrušeno pro všechny IP adresy člena.');
        $this->assertSame([], $this->redirects($other));
        $this->assertCount(1, $this->redirects($msg), 'jiná zpráva zůstává');
        $this->assertSame(['message_id' => $other, 'ip_count' => 2, 'scope' => 'member'], json_decode($this->lastAudit()->old_values, true));
    }

    // ── seznam a karta člena ─────────────────────────────────────────────────

    public function test_seznam_filtry_a_karta_clena(): void
    {
        $x = $this->member();
        $msg = $this->message(5);
        DB::table('messages_ip_addresses')->insert([
            ['message_id' => $msg, 'ip_address_id' => $x['device_ip'], 'datetime' => '2099-01-02 10:00:00', 'comment' => 'a'],
            ['message_id' => $msg, 'ip_address_id' => $x['direct_ip'], 'datetime' => '2099-01-01 10:00:00', 'comment' => 'b'],
        ]);

        $res = $this->as()->get(route('redirects.index', ['member' => $this->tag]))->assertOk()->assertViewIs('redirects.index');
        $rows = collect($res->viewData('redirections')->items());
        $this->assertSame([$x['device_ip'], $x['direct_ip']], $rows->pluck('ip_address_id')->map(fn ($i) => (int) $i)->all(), 'nejnovější první');
        $this->assertSame([$x['member'], "Char {$this->tag}", 5], [(int) $rows[0]->member_id, $rows[0]->member_name, (int) $rows[0]->msg_type]);
        $this->assertSame([$this->tag, '', ''], [$res->viewData('filterMember'), $res->viewData('filterIp'), $res->viewData('filterType')]);
        $this->assertSame(RedirectController::TYPE_LABELS, $res->viewData('typeLabels'));
        $this->assertTrue($res->viewData('canEdit') && $res->viewData('canDelete'));

        $ip = DB::table('ip_addresses')->where('id', $x['direct_ip'])->value('ip_address');
        $byIp = collect($this->as()->get(route('redirects.index', ['ip' => $ip, 'type' => 5]))->viewData('redirections')->items());
        $this->assertContains($x['direct_ip'], $byIp->pluck('ip_address_id')->map(fn ($i) => (int) $i)->all());
        $this->assertSame([], collect($this->as()->get(route('redirects.index', ['member' => $this->tag, 'type' => 6]))->viewData('redirections')->items())->all());

        $card = RedirectController::getMemberRedirections($x['member']);
        $this->assertSame([$msg], $card->keys()->map(fn ($i) => (int) $i)->all());
        $this->assertSame([$x['device_ip'], $x['direct_ip']], $card[$msg]->pluck('ip_address_id')->map(fn ($i) => (int) $i)->all());
        $this->as()->get(route('members.show', $x['member']))->assertOk()->assertViewHas('memberRedirections', fn ($r) => $r->keys()->map(fn ($i) => (int) $i)->all() === [$msg]);
    }

    // ── ACL ──────────────────────────────────────────────────────────────────

    public function test_prava(): void
    {
        $x = $this->member();
        $msg = $this->message();
        DB::table('messages_ip_addresses')->insert(['message_id' => $msg, 'ip_address_id' => $x['device_ip'], 'datetime' => now()]);

        $this->acl(['edit_all/Redirect_Controller/redirect', 'delete_all/Redirect_Controller/redirect']);
        $res = $this->as()->get(route('redirects.index'))->assertOk();
        $this->assertFalse($res->viewData('canEdit') || $res->viewData('canDelete'));
        $this->as()->get(route('redirects.activate-member', $x['member']))->assertForbidden();
        $this->as()->post(route('redirects.activate-ip', $x['device_ip']), ['message_id' => $msg])->assertForbidden();
        $this->as()->delete(route('redirects.delete', [$x['device_ip'], $msg]))->assertForbidden();
        $this->as()->delete(route('redirects.delete-member', [$x['member'], $msg]))->assertForbidden();
        $this->assertCount(1, $this->redirects($msg));
        $this->acl(['view_all/Redirect_Controller/redirect']);
        $this->as()->get(route('redirects.index'))->assertForbidden();
    }
}
