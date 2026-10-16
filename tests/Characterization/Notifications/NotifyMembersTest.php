<?php

namespace Tests\Characterization\Notifications;

use App\Models\Setting;
use App\Models\User;
use App\Services\AclService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Charakterizace upozornění členů (NotificationController) před přesunem do
 * domény Notifications. Jednotlivě (karta člena) a hromadně (JSON bulk_payload):
 * přesměrování IP (aktivace / ponechat / deaktivace), e-mail, SMS, audit, hlášky.
 *
 * Rozdíly zachycené testem: hromadně se respektuje whitelist (kromě zpráv
 * ignore_whitelist) a {comment} se dosadí do e-mailu/SMS; jednotlivě ne —
 * whitelist se ignoruje a text jde doslova (e-mail navíc bez access_time).
 */
class NotifyMembersTest extends DatabaseTestCase
{
    private const ACTIVATE = 1;
    private const KEEP = 2;
    private const DEACTIVATE = 3;

    private int $adminId;
    private int $messageId;
    private string $tag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = (int) DB::table('users')->where('type', 1)->value('id');
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturn(true)->byDefault();
        $acl->shouldReceive('flushUserCache')->andReturnNull();
        Setting::set('email_subject_prefix', 'CHAR');
        Setting::set('email_default_email', 'noc@example.invalid');
        Setting::set('sms_enabled', '1');
        Setting::set('sms_driver', '2');
        Setting::set('sms_sender_number', '+420700000000');
        $this->tag = substr(md5(uniqid('', true)), 0, 6);
        $this->messageId = (int) DB::table('messages')->insertGetId(['name' => 'Char výluka', 'text' => 'web', 'email_text' => 'Výluka {comment}',
            'sms_text' => 'SMS {comment}', 'type' => 0, 'self_cancel' => 0, 'ignore_whitelist' => 0]);
    }

    /** @return array{member:int, ips:int[], email:string, phone:string} */
    private function member(int $ipCount = 2, int $type = 2): array
    {
        $apId = (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id');
        $id = (int) DB::table('members')->insertGetId(['name' => 'Char Notif ' . $this->tag, 'type' => $type, 'registration' => 1, 'locked' => 0,
            'address_point_id' => $apId, 'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31']);
        $uid = (int) DB::table('users')->insertGetId(['member_id' => $id, 'login' => 'charnot_' . uniqid(), 'password' => Hash::make('x'), 'type' => 1,
            'application_password' => 'x', 'settings' => '', 'name' => 'C', 'surname' => 'N', 'comment' => '']);
        $email = 'charnot' . $id . '@example.invalid';
        $phone = '+420777' . str_pad((string) ($id % 1000000), 6, '0', STR_PAD_LEFT);
        foreach ([[20, $email], [21, $phone]] as [$t, $v]) {
            $cid = (int) DB::table('contacts')->insertGetId(['type' => $t, 'value' => $v]);
            DB::table('users_contacts')->insert(['user_id' => $uid, 'contact_id' => $cid]);
        }
        $dev = (int) DB::table('devices')->insertGetId(['name' => 'char-n', 'type' => 1, 'user_id' => $uid]);
        $iface = (int) DB::table('ifaces')->insertGetId(['type' => 1, 'device_id' => $dev, 'name' => 'e']);
        $ips = [];
        for ($i = 0; $i < $ipCount; $i++) {
            $ips[] = (int) DB::table('ip_addresses')->insertGetId(['iface_id' => $iface, 'ip_address' => '10.250.23.' . random_int(1, 250)]);
        }
        return ['member' => $id, 'ips' => $ips, 'email' => $email, 'phone' => $phone];
    }

    private function as()
    {
        return $this->actingAs(User::find($this->adminId));
    }

    private function redirected(array $ips): int
    {
        return DB::table('messages_ip_addresses')->where('message_id', $this->messageId)->whereIn('ip_address_id', $ips)->count();
    }

    // ── jednotlivě ───────────────────────────────────────────────────────────

    public function test_jednotlive_presmerovani_email_sms_audit(): void
    {
        $x = $this->member();
        $this->as()->post(route('notifications.member.notify', $x['member']), ['message_id' => $this->messageId, 'comment' => 'v pondělí',
            'redirection' => self::ACTIVATE, 'email' => self::ACTIVATE, 'sms' => self::ACTIVATE])
            ->assertRedirect(route('members.show', $x['member']))
            ->assertSessionHas('success', 'Přesměrování aktivováno pro 2 IP. Odesláno 1 e-mailů. Odesláno 1 SMS.');

        $r = DB::table('messages_ip_addresses')->where('message_id', $this->messageId)->where('ip_address_id', $x['ips'][0])->first();
        $this->assertSame([$this->adminId, 'v pondělí'], [(int) $r->user_id, $r->comment]);
        $mail = DB::table('email_queues')->where('to', $x['email'])->first();
        $this->assertSame(['CHAR :: Char výluka', 'Výluka {comment}', 'noc@example.invalid'], [$mail->subject, $mail->body, $mail->from], 'jednotlivě bez dosazení');
        $sms = DB::table('sms_messages')->where('receiver', $x['phone'])->first();
        $this->assertSame(['SMS {comment}', '+420700000000', 2, 1, 1, $this->adminId], [$sms->text, $sms->sender, (int) $sms->driver, (int) $sms->type, (int) $sms->state, (int) $sms->user_id]);
        $audit = DB::table('audit_logs')->where('auditable_type', 'messages_ip_addresses')->where('auditable_id', $x['member'])->where('action', 'notified')->first();
        $this->assertSame(['member_id' => $x['member'], 'redir_activated' => 2, 'redir_deactivated' => 0], json_decode($audit->new_values, true));

        // opakovaná aktivace = upsert (počítá se znovu), deaktivace maže
        $this->as()->post(route('notifications.member.notify', $x['member']), ['message_id' => $this->messageId, 'comment' => 'znovu', 'redirection' => self::ACTIVATE])
            ->assertSessionHas('success', 'Přesměrování aktivováno pro 2 IP.');
        $this->assertSame('znovu', DB::table('messages_ip_addresses')->where('message_id', $this->messageId)->where('ip_address_id', $x['ips'][0])->value('comment'));
        $this->as()->post(route('notifications.member.notify', $x['member']), ['message_id' => $this->messageId, 'redirection' => self::DEACTIVATE])
            ->assertSessionHas('success', 'Přesměrování deaktivováno pro 2 IP.');
        $this->assertSame(0, $this->redirected($x['ips']));
    }

    public function test_jednotlive_whitelist_se_ignoruje_sms_vypnute_a_chyby(): void
    {
        $x = $this->member(1);
        DB::table('members_whitelists')->insert(['member_id' => $x['member'], 'permanent' => 1, 'since' => '2000-01-01', 'until' => '9999-12-31']);
        Setting::set('sms_enabled', '0');
        $this->as()->post(route('notifications.member.notify', $x['member']), ['message_id' => $this->messageId, 'redirection' => self::ACTIVATE, 'sms' => self::ACTIVATE])
            ->assertSessionHas('success', 'Přesměrování aktivováno pro 1 IP.');
        $this->assertSame(1, $this->redirected($x['ips']), 'whitelist jednotlivě neplatí');

        $this->as()->post(route('notifications.member.notify', $x['member']), [])->assertSessionHasErrors(['message_id' => 'Vyberte zprávu.']);
        $this->as()->post(route('notifications.member.notify', $x['member']), ['message_id' => $this->messageId])->assertSessionHas('success', 'Žádná akce nebyla provedena.');
        $this->as()->post(route('notifications.member.notify', 999999999), ['message_id' => $this->messageId])->assertNotFound();
        $this->as()->get(route('notifications.member', $x['member']))->assertOk()->assertViewHas('messages', fn ($m) => $m->has($this->messageId));
    }

    // ── hromadně ─────────────────────────────────────────────────────────────

    private function bulk(array $payload, string $comment = 'pozn')
    {
        return $this->as()->post(route('notifications.members.notify', $this->messageId), ['comment' => $comment, 'bulk_payload' => json_encode($payload)]);
    }

    public function test_hromadne_presmerovani_whitelist_email_sms(): void
    {
        $a = $this->member(2);
        $b = $this->member(1);
        $wl = $this->member(1);
        $keep = $this->member(1);
        DB::table('members_whitelists')->insert(['member_id' => $wl['member'], 'permanent' => 0, 'since' => '2000-01-01', 'until' => '9999-12-31']);
        DB::table('messages_ip_addresses')->insert(['message_id' => $this->messageId, 'ip_address_id' => $keep['ips'][0], 'comment' => 'stare', 'datetime' => now()]);

        $this->bulk([
            'redirection' => [$a['member'] => self::ACTIVATE, $b['member'] => self::ACTIVATE, $wl['member'] => self::ACTIVATE, $keep['member'] => self::KEEP],
            'email' => [$a['member']],
            'sms'   => [$b['member']],
        ], 'zítra')->assertRedirect(route('notifications.members', $this->messageId))
            ->assertSessionHas('success', 'Přesměrování aktivováno pro 3 IP. Odesláno 1 e-mailů. Odesláno 1 SMS.');

        $this->assertSame([2, 1, 0, 1], [$this->redirected($a['ips']), $this->redirected($b['ips']), $this->redirected($wl['ips']), $this->redirected($keep['ips'])]);
        $this->assertSame('stare', DB::table('messages_ip_addresses')->where('ip_address_id', $keep['ips'][0])->value('comment'), 'KEEP nesahá');
        $this->assertSame('Výluka zítra', DB::table('email_queues')->where('to', $a['email'])->value('body'), 'hromadně s {comment}');
        $this->assertNotNull(DB::table('email_queues')->where('to', $a['email'])->value('access_time'));
        $this->assertSame('SMS zítra', DB::table('sms_messages')->where('receiver', $b['phone'])->value('text'));

        $audit = DB::table('audit_logs')->where('auditable_type', 'messages_ip_addresses')->where('auditable_id', $this->messageId)->where('action', 'notified')->first();
        $this->assertSame(['message_id' => $this->messageId, 'redir_activated' => 3, 'redir_deactivated' => 0], json_decode($audit->new_values, true));

        // ignore_whitelist + deaktivace
        DB::table('messages')->where('id', $this->messageId)->update(['ignore_whitelist' => 1]);
        $this->bulk(['redirection' => [$wl['member'] => self::ACTIVATE, $a['member'] => self::DEACTIVATE]])
            ->assertSessionHas('success', 'Přesměrování aktivováno pro 1 IP. Přesměrování deaktivováno pro 2 IP.');
        $this->assertSame([1, 0], [$this->redirected($wl['ips']), $this->redirected($a['ips'])]);
    }

    public function test_hromadne_bez_akce_a_seznam_clenu(): void
    {
        $x = $this->member(0);
        $this->bulk(['redirection' => [$x['member'] => self::ACTIVATE]])->assertSessionHas('success', 'Žádná akce nebyla provedena.');
        $this->bulk([])->assertSessionHas('success', 'Žádná akce nebyla provedena.');
        $this->as()->post(route('notifications.members.notify', $this->messageId), ['bulk_payload' => 'neni json'])->assertSessionHas('success', 'Žádná akce nebyla provedena.');

        $y = $this->member(1);
        DB::table('messages_ip_addresses')->insert(['message_id' => $this->messageId, 'ip_address_id' => $y['ips'][0], 'datetime' => now()]);
        $former = $this->member(1, 16);
        $rows = $this->as()->get(route('notifications.members', $this->messageId))->assertOk()->viewData('members');
        $row = $rows->firstWhere('id', $y['member']);
        $this->assertSame([1, 1, 1], [(int) $row->has_redirection, (int) $row->email_contacts_count, (int) $row->phone_contacts_count]);
        $this->assertNull($rows->firstWhere('id', $former['member']), 'bývalí se nenabízí');

        $this->as()->get(route('notifications.members', 999999999))->assertNotFound();
        $this->as()->get(route('notifications.members.select'))->assertOk()->assertViewHas('messages', fn ($m) => $m->has($this->messageId));
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturn(false);
        $acl->shouldReceive('flushUserCache')->andReturnNull();
        $this->bulk([])->assertForbidden();
    }
}
