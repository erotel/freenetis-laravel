<?php

namespace Tests\Characterization\Notifications;

use App\Domains\Acl\AclService;
use App\Domains\Notifications\Channels\EmailSenderService;
use App\Models\EmailQueue;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Charakterizace správy fronty e-mailů (EmailQueueController) před přesunem
 * do domény Notifications: čekající / odeslané (posledních 200 nebo vše,
 * filtry), detail s inline přílohami (cid → URL), stažení přílohy jen
 * z povolených adresářů, znovuodeslání, mazání (jen čekající / všechny
 * čekající / odeslané podle filtru), ACL.
 */
class EmailQueueAdminTest extends DatabaseTestCase
{
    private int $adminId;
    private string $tag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = (int) DB::table('users')->where('type', 1)->value('id');
        $this->tag = 'eq' . substr(md5(uniqid('', true)), 0, 8);
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

    private function mail(int $state, ?string $to = null, string $subject = 'Předmět'): int
    {
        return (int) DB::table('email_queues')->insertGetId(['from' => 'noreply@example.test', 'to' => $to ?? "{$this->tag}@example.invalid",
            'subject' => "{$subject} {$this->tag}", 'body' => '<p>Tělo <img src="cid:qr.png"></p>', 'state' => $state]);
    }

    private function ids($res): array
    {
        return collect($res->viewData('emails')->items())->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    // ── seznamy ──────────────────────────────────────────────────────────────

    public function test_cekajici_a_odeslane_s_filtry(): void
    {
        $n1 = $this->mail(0);
        $n2 = $this->mail(0, "jiny{$this->tag}@example.invalid", 'Faktura');
        $s1 = $this->mail(1);
        $f1 = $this->mail(2);

        $res = $this->as()->get(route('email_queues.unsent', ['subject' => $this->tag]))->assertOk()->assertViewIs('email_queues.unsent');
        $this->assertSame([$n2, $n1], $this->ids($res), 'nejnovější první, jen stav 0');
        $this->assertSame([true, true, false, $this->tag], [$res->viewData('canSend'), $res->viewData('canDelete'), $res->viewData('loadAll'), $res->viewData('filterSubj')]);
        $this->assertSame([$n2], $this->ids($this->as()->get(route('email_queues.unsent', ['to' => "jiny{$this->tag}"]))));
        $this->assertSame([$n2], $this->ids($this->as()->get(route('email_queues.unsent', ['subject' => "Faktura {$this->tag}", 'all' => 1]))));
        $this->assertTrue($this->as()->get(route('email_queues.unsent', ['all' => 1]))->viewData('loadAll'));

        $sent = $this->as()->get(route('email_queues.sent', ['subject' => $this->tag, 'from' => 'noreply@example']))->assertOk()->assertViewIs('email_queues.sent');
        $this->assertSame([$s1], $this->ids($sent));
        $this->assertNotContains($f1, $this->ids($this->as()->get(route('email_queues.unsent', ['subject' => $this->tag]))), 'chybné ne');
    }

    public function test_jen_poslednich_200(): void
    {
        $old = $this->mail(1);
        $rows = [];
        for ($i = 0; $i < 200; $i++) {
            $rows[] = ['from' => 'x@example.test', 'to' => 'x@example.invalid', 'subject' => 'bulk', 'body' => '', 'state' => 1];
        }
        DB::table('email_queues')->insert($rows);
        $this->assertSame([], $this->ids($this->as()->get(route('email_queues.sent', ['subject' => $this->tag]))), 'starší než 200 posledních nevidí');
        $this->assertSame([$old], $this->ids($this->as()->get(route('email_queues.sent', ['subject' => $this->tag, 'all' => 1]))));
    }

    // ── detail a přílohy ─────────────────────────────────────────────────────

    public function test_detail_a_prilohy(): void
    {
        $id = $this->mail(0);
        $dir = storage_path('app/private/qr');
        @mkdir($dir, 0775, true);
        $ok = $dir . "/{$this->tag}.png";
        file_put_contents($ok, 'PNG');
        $inline = (int) DB::table('email_queue_attachments')->insertGetId(['email_queue_id' => $id, 'path' => $ok, 'name' => 'qr.png', 'mime' => 'image/png', 'inline' => 1, 'created_at' => now()]);
        $outside = (int) DB::table('email_queue_attachments')->insertGetId(['email_queue_id' => $id, 'path' => '/etc/hostname', 'name' => 'x', 'mime' => 'text/plain', 'inline' => 0, 'created_at' => now()]);
        $missing = (int) DB::table('email_queue_attachments')->insertGetId(['email_queue_id' => $id, 'path' => $dir . '/neni.png', 'name' => 'n', 'mime' => '', 'inline' => 0, 'created_at' => now()]);

        try {
            $res = $this->as()->get(route('email_queues.show', $id))->assertOk()->assertViewIs('email_queues.show');
            $this->assertSame('<p>Tělo <img src="' . route('email_queues.attachment', [$id, $inline]) . '"></p>', $res->viewData('bodyHtml'));

            $dl = $this->as()->get(route('email_queues.attachment', [$id, $inline]))->assertOk();
            $this->assertSame('image/png', $dl->headers->get('Content-Type'));
            $this->assertStringContainsString('qr.png', $dl->headers->get('Content-Disposition'));
            $this->as()->get(route('email_queues.attachment', [$id, $outside]))->assertForbidden();
            $this->as()->get(route('email_queues.attachment', [$id, $missing]))->assertNotFound();
            $this->as()->get(route('email_queues.attachment', [$this->mail(0), $inline]))->assertNotFound('příloha jiného e-mailu');
            $this->as()->get(route('email_queues.show', 999999999))->assertNotFound();
        } finally {
            @unlink($ok);
        }
    }

    // ── znovuodeslání, mazání ────────────────────────────────────────────────

    public function test_detail_neukaze_token_resetu_hesla(): void
    {
        $token = \Tests\Support\Fake::secret('resettok');
        $url   = route('forgotten-password.reset') . '?request=' . $token;
        $id = (int) DB::table('email_queues')->insertGetId(['from' => 'noreply@example.test', 'to' => "{$this->tag}@example.invalid",
            'subject' => "Obnova hesla {$this->tag}", 'body' => "<p><a href=\"{$url}\">{$url}</a></p>", 'state' => 0]);

        $html = $this->as()->get(route('email_queues.show', $id))->assertOk()->getContent();
        $this->assertStringNotContainsString($token, $html);
        $this->assertStringContainsString('forgotten-password/reset?request=•••', html_entity_decode($html));
        $this->assertSame("<p><a href=\"{$url}\">{$url}</a></p>", DB::table('email_queues')->where('id', $id)->value('body'), 'čekající e-mail má token pro odeslání');
    }

    public function test_znovuodeslani(): void
    {
        $id = $this->mail(2);
        $sender = $this->mock(EmailSenderService::class);
        $sender->shouldReceive('sendOne')->once()->with(\Mockery::on(fn ($q) => $q->id === $id && $q->state === EmailQueue::STATE_NEW))->andReturnTrue();
        $this->as()->post(route('email_queues.resend', $id))->assertRedirect(route('email_queues.unsent'))->assertSessionHas('success', "E-mail #{$id} byl úspěšně odeslán.");
        $row = DB::table('email_queues')->where('id', $id)->first();
        $this->assertSame(1, (int) $row->state);
        $this->assertNotNull($row->access_time);

        $id2 = $this->mail(0);
        $sender = $this->mock(EmailSenderService::class);
        $sender->shouldReceive('sendOne')->andReturnFalse();
        $this->as()->post(route('email_queues.resend', $id2))
            ->assertSessionHas('error', "E-mail #{$id2} se nepodařilo odeslat. Zkontrolujte nastavení SMTP.");
        $this->assertSame(2, (int) DB::table('email_queues')->where('id', $id2)->value('state'));
        $this->as()->post(route('email_queues.resend', 999999999))->assertNotFound();
    }

    public function test_mazani(): void
    {
        $n = $this->mail(0);
        $s = $this->mail(1);
        $s2 = $this->mail(1, null, 'Jiny');

        $this->as()->delete(route('email_queues.destroy', $s))->assertRedirect(route('email_queues.unsent'))->assertSessionHas('error', 'Lze smazat pouze čekající e-maily.');
        $this->as()->delete(route('email_queues.destroy', $n))->assertSessionHas('success', "E-mail #{$n} byl smazán.");
        $this->assertFalse(DB::table('email_queues')->where('id', $n)->exists());

        $this->as()->delete(route('email_queues.destroy-sent'), ['subject' => "Jiny {$this->tag}"])
            ->assertRedirect(route('email_queues.sent'))->assertSessionHas('success', 'Smazáno 1 odeslaných e-mailů.');
        $this->assertSame([true, false], [DB::table('email_queues')->where('id', $s)->exists(), DB::table('email_queues')->where('id', $s2)->exists()]);

        $this->mail(0);
        $pending = DB::table('email_queues')->where('state', 0)->count();
        $this->as()->delete(route('email_queues.destroy-unsent'))->assertSessionHas('success', "Smazáno {$pending} čekajících e-mailů.");
        $this->assertSame(0, DB::table('email_queues')->where('state', 0)->count());
        $this->assertTrue(DB::table('email_queues')->where('id', $s)->exists(), 'odeslané zůstávají');
    }

    // ── ACL ──────────────────────────────────────────────────────────────────

    public function test_prava(): void
    {
        $id = $this->mail(0);
        $this->acl(['new_all/Email_queues_Controller/email_queue', 'delete_all/Email_queues_Controller/email_queue']);
        $this->as()->post(route('email_queues.resend', $id))->assertForbidden();
        $this->as()->delete(route('email_queues.destroy', $id))->assertForbidden();
        $this->as()->delete(route('email_queues.destroy-unsent'))->assertForbidden();
        $this->as()->delete(route('email_queues.destroy-sent'))->assertForbidden();
        $this->assertFalse($this->as()->get(route('email_queues.show', $id))->viewData('canSend'));
        $this->acl(['view_all/Email_queues_Controller/email_queue']);
        foreach ([route('email_queues.unsent'), route('email_queues.sent'), route('email_queues.show', $id), route('email_queues.attachment', [$id, 1])] as $url) {
            $this->as()->get($url)->assertForbidden();
        }
    }
}
