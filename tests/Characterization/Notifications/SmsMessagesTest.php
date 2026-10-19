<?php

namespace Tests\Characterization\Notifications;

use App\Models\Setting;
use App\Models\SmsMessage;
use Illuminate\Support\Facades\DB;
use Tests\Characterization\Catalog\CatalogTestCase;

/**
 * Charakterizace SMS zpráv (SmsMessageController) před přesunem do domény
 * Notifications: seznam (posledních 200 / hledání čísla v celé historii,
 * filtry), detail (přijatá → přečtená), zařazení do fronty (předvolba 420,
 * přepis bez diakritiky), smazání neodeslaných, ACL.
 */
class SmsMessagesTest extends CatalogTestCase
{
    private function sms(array $over = []): int
    {
        return (int) DB::table('sms_messages')->insertGetId($over + ['user_id' => $this->adminId, 'stamp' => now(), 'send_date' => now(), 'text' => 'char',
            'sender' => '420700000000', 'receiver' => '420777' . random_int(100000, 999999), 'driver' => 3, 'type' => SmsMessage::TYPE_SENT, 'state' => SmsMessage::STATE_SENT_OK]);
    }

    private function ids($res): array
    {
        return collect($res->viewData('items')->items())->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    public function test_seznam_a_detail(): void
    {
        $num = '420' . random_int(600000000, 699999999);
        $old = $this->sms(['receiver' => $num]);
        DB::table('sms_messages')->insert(array_fill(0, 3, ['user_id' => $this->adminId, 'stamp' => now(), 'send_date' => now(), 'text' => 'x', 'sender' => '1', 'receiver' => '2', 'driver' => 3, 'type' => 1, 'state' => 0]));
        $in = $this->sms(['type' => SmsMessage::TYPE_RECEIVED, 'state' => SmsMessage::STATE_RECEIVED_UNREAD, 'sender' => $num, 'receiver' => '420700000000']);

        $all = $this->as()->get(route('sms_messages.index'))->assertOk();
        $this->assertSame($in, $this->ids($all)[0], 'nejnovější první');
        $this->assertTrue($all->viewData('canSend') && $all->viewData('canDelete'));
        $this->assertSame([$in, $old], $this->ids($this->as()->get(route('sms_messages.index', ['phone' => '+' . substr($num, 0, 3) . ' ' . substr($num, 3)]))));
        $this->assertSame([$in], $this->ids($this->as()->get(route('sms_messages.index', ['phone' => $num, 'type' => '0']))));
        $this->assertSame([$old], $this->ids($this->as()->get(route('sms_messages.index', ['phone' => $num, 'state' => '0', 'type' => '1']))));

        $this->as()->get(route('sms_messages.show', $in))->assertOk()->assertViewHas('sms', fn ($s) => (int) $s->id === $in);
        $this->assertSame(SmsMessage::STATE_RECEIVED_READ, (int) DB::table('sms_messages')->where('id', $in)->value('state'));
        $this->as()->get(route('sms_messages.show', 999999999))->assertNotFound();
    }

    public function test_odeslani_a_smazani_neodeslanych(): void
    {
        Setting::set('sms_sender_number', '420700000001');
        Setting::set('sms_driver', '2');
        $this->as()->get(route('sms_messages.create'))->assertOk()->assertViewHas('senderNumber', '420700000001');
        $this->as()->post(route('sms_messages.store'), ['receiver' => '777123456', 'text' => "Žluťoučký kůň {$this->tag}", 'send_date' => '2099-01-01 10:00'])
            ->assertRedirect(route('sms_messages.index'))->assertSessionHas('success', 'SMS zpráva byla přidána do fronty.');
        $r = DB::table('sms_messages')->where('text', 'like', "%{$this->tag}")->first();
        $this->assertSame(['420777123456', '420700000001', 2, SmsMessage::TYPE_SENT, SmsMessage::STATE_SENT_UNSENT, $this->adminId, '2099-01-01 10:00:00'],
            [$r->receiver, $r->sender, (int) $r->driver, (int) $r->type, (int) $r->state, (int) $r->user_id, $r->send_date]);
        $this->assertStringStartsWith('Zlut', $r->text);
        $this->as()->post(route('sms_messages.store'), ['receiver' => '12ab', 'text' => '', 'send_date' => 'x'])->assertSessionHasErrors(['receiver', 'text', 'send_date']);

        $sent = $this->sms();
        $this->as()->delete(route('sms_messages.destroy-unsent'))->assertRedirect(route('sms_messages.index'))->assertSessionHas('success', 'Neodeslané SMS byly smazány.');
        $this->assertFalse(DB::table('sms_messages')->where('id', $r->id)->exists());
        $this->assertTrue(DB::table('sms_messages')->where('id', $sent)->exists());
    }

    public function test_prava(): void
    {
        $id = $this->sms();
        $this->acl(['view_all/Sms_Controller/sms', 'new_all/Sms_Controller/sms', 'delete_all/Sms_Controller/sms']);
        foreach ([route('sms_messages.index'), route('sms_messages.show', $id), route('sms_messages.create')] as $url) {
            $this->as()->get($url)->assertForbidden();
        }
        $this->as()->post(route('sms_messages.store'), [])->assertForbidden();
        $this->as()->delete(route('sms_messages.destroy-unsent'))->assertForbidden();
    }
}
