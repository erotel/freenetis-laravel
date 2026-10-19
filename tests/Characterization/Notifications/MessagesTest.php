<?php

namespace Tests\Characterization\Notifications;

use App\Models\User;
use App\Services\AclService;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Charakterizace šablon zpráv (MessageController) před přesunem do domény
 * Notifications: seznam (systémové podle typu, uživatelské podle jména),
 * detail s aktivními IP, založení (vždy uživatelská), úprava, smazání (jen
 * uživatelské, i s přesměrováními), deaktivace pro IP, ACL.
 */
class MessagesTest extends DatabaseTestCase
{
    private int $adminId;
    private string $tag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = (int) DB::table('users')->where('type', 1)->value('id');
        $this->tag = 'Ms' . substr(md5(uniqid('', true)), 0, 8);
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

    private function data(array $over = []): array
    {
        return $over + ['name' => "Char {$this->tag}", 'text' => '<p>Text</p>', 'email_text' => '<p>Mail</p>', 'sms_text' => 'SMS', 'self_cancel' => 1, 'ignore_whitelist' => '1'];
    }

    private function ip(): int
    {
        return (int) DB::table('ip_addresses')->insertGetId(['ip_address' => '10.246.' . random_int(1, 250) . '.' . random_int(1, 250)]);
    }

    public function test_seznam_zalozeni_uprava(): void
    {
        $res = $this->as()->get(route('messages.index'))->assertOk()->assertViewIs('messages.index');
        $this->assertSame(DB::table('messages')->where('type', '>', 0)->orderBy('type')->pluck('id')->all(), $res->viewData('systemMessages')->pluck('id')->all());
        $this->assertSame(DB::table('messages')->where('type', 0)->orderBy('name')->pluck('id')->all(), $res->viewData('userMessages')->pluck('id')->all());
        $this->as()->get(route('messages.create'))->assertOk();

        $this->as()->post(route('messages.store'), $this->data(['type' => 5]))->assertRedirect(route('messages.index'))->assertSessionHas('success', 'Zpráva byla vytvořena.');
        $m = DB::table('messages')->where('name', "Char {$this->tag}")->first();
        $this->assertSame([0, '<p>Text</p>', '<p>Mail</p>', 'SMS', 1, 1], [(int) $m->type, $m->text, $m->email_text, $m->sms_text, (int) $m->self_cancel, (int) $m->ignore_whitelist],
            'vždy uživatelská');
        $this->as()->post(route('messages.store'), $this->data(['name' => '', 'self_cancel' => 3, 'sms_text' => str_repeat('x', 761)]))
            ->assertSessionHasErrors(['name', 'self_cancel', 'sms_text']);

        $this->as()->get(route('messages.edit', $m->id))->assertOk()->assertViewHas('message', fn ($x) => (int) $x->id === (int) $m->id);
        $this->as()->put(route('messages.update', $m->id), $this->data(['name' => "Upr {$this->tag}", 'self_cancel' => 0, 'ignore_whitelist' => '0', 'type' => 5]))
            ->assertRedirect(route('messages.show', $m->id))->assertSessionHas('success', 'Zpráva byla upravena.');
        $u = DB::table('messages')->where('id', $m->id)->first();
        $this->assertSame(["Upr {$this->tag}", 0, 0, 0], [$u->name, (int) $u->self_cancel, (int) $u->ignore_whitelist, (int) $u->type]);
        $this->as()->put(route('messages.update', 999999999), $this->data())->assertNotFound();
    }

    public function test_detail_deaktivace_smazani(): void
    {
        $this->as()->post(route('messages.store'), $this->data());
        $id = (int) DB::table('messages')->where('name', "Char {$this->tag}")->value('id');
        [$a, $b] = [$this->ip(), $this->ip()];
        DB::table('messages_ip_addresses')->insert([
            ['message_id' => $id, 'ip_address_id' => $a, 'user_id' => $this->adminId, 'comment' => 'a', 'datetime' => '2099-01-01 10:00:00'],
            ['message_id' => $id, 'ip_address_id' => $b, 'user_id' => null, 'comment' => 'b', 'datetime' => '2099-01-02 10:00:00'],
        ]);

        $show = $this->as()->get(route('messages.show', $id))->assertOk()->assertViewIs('messages.show');
        $rows = $show->viewData('activeIps');
        $this->assertSame([$b, $a], $rows->pluck('ip_address_id')->map(fn ($i) => (int) $i)->all(), 'nejnovější první');
        $this->assertSame([null, User::find($this->adminId)->login], $rows->pluck('activated_by')->all());

        $this->as()->from(route('messages.show', $id))->delete(route('messages.deactivate', [$id, $a]))
            ->assertRedirect(route('messages.show', $id))->assertSessionHas('success', 'Zpráva byla deaktivována.');
        $this->assertSame([$b], DB::table('messages_ip_addresses')->where('message_id', $id)->pluck('ip_address_id')->map(fn ($i) => (int) $i)->all());
        $audit = DB::table('audit_logs')->where('auditable_type', 'messages_ip_addresses')->orderByDesc('id')->first();
        $this->assertSame(['deleted', $a, ['message_id' => $id]], [$audit->action, (int) $audit->auditable_id, json_decode($audit->old_values, true)]);

        $system = (int) DB::table('messages')->where('type', '>', 0)->value('id');
        $this->as()->from(route('messages.show', $system))->delete(route('messages.destroy', $system))
            ->assertRedirect(route('messages.show', $system))->assertSessionHas('error', 'Systémové zprávy nelze smazat.');
        $this->as()->delete(route('messages.destroy', $id))->assertRedirect(route('messages.index'))->assertSessionHas('success', 'Zpráva byla smazána.');
        $this->assertFalse(DB::table('messages')->where('id', $id)->exists());
        $this->assertFalse(DB::table('messages_ip_addresses')->where('message_id', $id)->exists());
        $this->as()->get(route('messages.show', 999999999))->assertNotFound();
    }

    public function test_prava(): void
    {
        $id = (int) DB::table('messages')->where('type', 0)->value('id');
        $this->acl(['view_all/Messages_Controller/message', 'new_all/Messages_Controller/message', 'edit_all/Messages_Controller/message', 'delete_all/Messages_Controller/message']);
        $this->as()->get(route('messages.show', $id))->assertForbidden();
        $this->as()->get(route('messages.create'))->assertForbidden();
        $this->as()->post(route('messages.store'), $this->data())->assertForbidden();
        $this->as()->get(route('messages.edit', $id))->assertForbidden();
        $this->as()->put(route('messages.update', $id), $this->data())->assertForbidden();
        $this->as()->delete(route('messages.destroy', $id))->assertForbidden();
        $this->as()->delete(route('messages.deactivate', [$id, 1]))->assertForbidden();
        $this->assertTrue(DB::table('messages')->where('id', $id)->exists());
    }

    /**
     * Ruční aktivace jde jen přes správu přesměrování (jen zprávy z nabídky).
     * Nepoužívaný POST messages/{id}/activate ji obcházel — i pro systémové zprávy.
     */
    public function test_aktivace_mimo_spravu_presmerovani_neexistuje(): void
    {
        $system = (int) DB::table('messages')->where('type', 4)->value('id');
        $this->as()->post("/messages/{$system}/activate", ['ip_address_ids' => [$this->ip()]])->assertNotFound();
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('messages.activate'));
    }
}
