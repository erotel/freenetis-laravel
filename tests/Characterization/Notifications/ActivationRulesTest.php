<?php

namespace Tests\Characterization\Notifications;

use App\Domains\Acl\AclService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Charakterizace pravidel automatické aktivace zpráv (MessageAutoSettingController)
 * před přesunem do domény Notifications: seznam, založení (jen dlužník /
 * upomínka 5, 6, 25, 26), úprava, smazání, audit, ACL.
 */
class ActivationRulesTest extends DatabaseTestCase
{
    private int $adminId;
    private int $debtorMsg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = (int) DB::table('users')->where('type', 1)->value('id');
        $this->debtorMsg = (int) DB::table('messages')->where('type', 5)->value('id');
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

    private function rules(int $msg): array
    {
        return DB::table('messages_automatical_activations')->where('message_id', $msg)->orderBy('id')
            ->get(['type', 'attribute', 'redirection_enabled', 'email_enabled', 'sms_enabled', 'send_activation_to_email'])
            ->map(fn ($r) => array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, (array) $r))->all();
    }

    public function test_zalozeni_uprava_smazani(): void
    {
        $m = $this->debtorMsg;
        DB::table('messages_automatical_activations')->where('message_id', $m)->delete();

        $this->as()->get(route('message-auto-settings.create', $m))->assertOk()->assertViewHas('typeLabels');
        $this->as()->post(route('message-auto-settings.store', $m), ['type' => 1, 'attribute' => '25/9', 'email_enabled' => '1', 'redirection_enabled' => '1',
            'send_activation_to_email' => 'admin@example.test'])
            ->assertRedirect(route('message-auto-settings.index', $m))->assertSessionHas('success', 'Pravidlo automatické aktivace bylo přidáno.');
        $this->assertSame([['type' => 1, 'attribute' => '25/9', 'redirection_enabled' => 1, 'email_enabled' => 1, 'sms_enabled' => 0, 'send_activation_to_email' => 'admin@example.test']],
            $this->rules($m));
        $audit = DB::table('audit_logs')->where('auditable_type', 'messages_automatical_activations')->orderByDesc('id')->first();
        $this->assertSame(['created', $m, ['message_id' => $m, 'type' => 1, 'attribute' => '25/9']], [$audit->action, (int) $audit->auditable_id, json_decode($audit->new_values, true)]);

        $index = $this->as()->get(route('message-auto-settings.index', $m))->assertOk()->assertViewIs('message_auto_settings.index');
        $id = (int) $index->viewData('rules')->first()->id;
        $this->as()->get(route('message-auto-settings.edit', [$m, $id]))->assertOk()->assertViewHas('rule', fn ($r) => (int) $r->id === $id);
        $this->as()->put(route('message-auto-settings.update', [$m, $id]), ['type' => 3, 'attribute' => '/7', 'sms_enabled' => '1'])
            ->assertRedirect(route('message-auto-settings.index', $m))->assertSessionHas('success', 'Pravidlo bylo upraveno.');
        $this->assertSame([['type' => 3, 'attribute' => '/7', 'redirection_enabled' => 0, 'email_enabled' => 0, 'sms_enabled' => 1, 'send_activation_to_email' => '']], $this->rules($m));
        $this->as()->get(route('message-auto-settings.edit', [$m, 999999999]))->assertNotFound();

        $this->as()->delete(route('message-auto-settings.destroy', [$m, $id]))->assertRedirect(route('messages.show', $m))->assertSessionHas('success', 'Pravidlo bylo smazáno.');
        $this->assertSame([], $this->rules($m));
    }

    public function test_jen_dluznik_a_upominka_a_validace(): void
    {
        $user = (int) DB::table('messages')->where('type', 0)->value('id');
        $this->as()->get(route('message-auto-settings.create', $user))->assertForbidden();
        $this->as()->post(route('message-auto-settings.store', $user), ['type' => 3, 'attribute' => '/1'])->assertForbidden();
        $this->as()->post(route('message-auto-settings.store', $this->debtorMsg), ['type' => 9, 'send_activation_to_email' => 'x'])
            ->assertSessionHasErrors(['type', 'send_activation_to_email']);
        $this->as()->get(route('message-auto-settings.index', 999999999))->assertNotFound();
    }

    /** Atribut musí odpovídat typu — jinak se pravidlo potichu spouštělo o půlnoci (hodina 0). */
    public function test_atribut_podle_typu(): void
    {
        $m = $this->debtorMsg;
        $post = fn (int $type, ?string $attr) => $this->as()->from(route('message-auto-settings.create', $m))
            ->post(route('message-auto-settings.store', $m), ['type' => $type, 'attribute' => $attr]);

        $post(1, '25')->assertSessionHasErrors(['attribute' => 'Měsíční pravidlo potřebuje den a hodinu ve tvaru „DD/H" (den 1–31, hodina 0–23), např. 25/9.']);
        $post(1, '32/9')->assertSessionHasErrors('attribute');
        $post(1, '25/24')->assertSessionHasErrors('attribute');
        $post(2, '8/9')->assertSessionHasErrors(['attribute' => 'Týdenní pravidlo potřebuje den a hodinu ve tvaru „D/H" (1 = Po … 7 = Ne, hodina 0–23), např. 1/9.']);
        $post(3, 'abc')->assertSessionHasErrors(['attribute' => 'Pravidlo potřebuje hodinu ve tvaru „/H" (0–23), např. /9.']);
        $post(6, '')->assertSessionHasErrors('attribute');
        foreach ([[1, '25/9'], [2, '7/0'], [3, '/9'], [3, '0'], [4, '/23'], [6, '/1'], [5, '']] as [$type, $attr]) {
            $post($type, $attr)->assertSessionHasNoErrors();
        }
    }

    public function test_prava(): void
    {
        $m = $this->debtorMsg;
        $id = (int) DB::table('messages_automatical_activations')->insertGetId(['message_id' => $m, 'type' => 3, 'attribute' => '/1', 'redirection_enabled' => 0,
            'email_enabled' => 0, 'sms_enabled' => 0, 'send_activation_to_email' => '']);
        $this->acl(['view_all/Messages_Controller/message', 'new_all/Messages_Controller/message', 'edit_all/Messages_Controller/message', 'delete_all/Messages_Controller/message']);
        $this->as()->get(route('message-auto-settings.index', $m))->assertForbidden();
        $this->as()->get(route('message-auto-settings.create', $m))->assertForbidden();
        $this->as()->post(route('message-auto-settings.store', $m), [])->assertForbidden();
        $this->as()->get(route('message-auto-settings.edit', [$m, $id]))->assertForbidden();
        $this->as()->put(route('message-auto-settings.update', [$m, $id]), [])->assertForbidden();
        $this->as()->delete(route('message-auto-settings.destroy', [$m, $id]))->assertForbidden();
        $this->assertTrue(DB::table('messages_automatical_activations')->where('id', $id)->exists());
    }
}
