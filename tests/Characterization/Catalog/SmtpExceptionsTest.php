<?php

namespace Tests\Characterization\Catalog;

use Illuminate\Support\Facades\DB;

/**
 * Charakterizace SMTP výjimek (SmtpExceptionController) před přesunem do
 * domény Network: seznam podle IP, založení (zadavatel + datum), úprava
 * jen IP, jedinečnost IP, jen IPv4, ACL.
 */
class SmtpExceptionsTest extends CatalogTestCase
{
    private string $net;

    protected function setUp(): void
    {
        parent::setUp();
        $this->net = '10.242.' . random_int(1, 250) . '.';
    }

    public function test_zalozeni_uprava_seznam(): void
    {
        $this->as()->get(route('smtp-exceptions.create'))->assertOk()->assertViewHas('action', 'create');
        $this->as()->post(route('smtp-exceptions.store'), ['intip' => " {$this->net}20 "])
            ->assertRedirect(route('smtp-exceptions.index'))->assertSessionHas('success', 'SMTP výjimka byla uložena.');
        $r = DB::table('smtp_exceptions')->where('intip', "{$this->net}20")->first();
        $login = DB::table('users')->where('id', $this->adminId)->value('login');
        $this->assertSame([$login, now()->toDateString()], [$r->user, substr($r->datum, 0, 10)]);

        $this->as()->from(route('smtp-exceptions.create'))->post(route('smtp-exceptions.store'), ['intip' => "{$this->net}20"])
            ->assertRedirect(route('smtp-exceptions.create'))->assertSessionHasErrors(['intip' => 'Tato IP adresa už má SMTP výjimku.']);
        foreach (['abc', '2a07:9c0::1', ''] as $bad) {
            $this->as()->post(route('smtp-exceptions.store'), ['intip' => $bad])->assertSessionHasErrors(['intip' => 'Neplatná IPv4 adresa.']);
        }
        $this->as()->post(route('smtp-exceptions.store'), ['intip' => "{$this->net}3"])->assertSessionHasNoErrors();

        $rows = $this->as()->get(route('smtp-exceptions.index'))->assertOk()->assertViewHas('canEdit', true)->viewData('rows');
        $mine = $rows->filter(fn ($x) => str_starts_with($x->intip, $this->net))->pluck('intip')->values()->all();
        $this->assertSame(["{$this->net}3", "{$this->net}20"], $mine, 'řazení podle IP číselně');

        DB::table('smtp_exceptions')->where('id', $r->id)->update(['user' => 'puvodni', 'datum' => '2020-01-01']);
        $this->as()->get(route('smtp-exceptions.edit', $r->id))->assertOk()->assertViewHas('record', fn ($x) => (int) $x->id === (int) $r->id);
        $this->as()->put(route('smtp-exceptions.update', $r->id), ['intip' => "{$this->net}3"])->assertSessionHasErrors(['intip' => 'Tato IP adresa už má SMTP výjimku.']);
        $this->as()->put(route('smtp-exceptions.update', $r->id), ['intip' => "{$this->net}20"])->assertSessionHasNoErrors();
        $this->as()->put(route('smtp-exceptions.update', $r->id), ['intip' => "{$this->net}21"])
            ->assertRedirect(route('smtp-exceptions.index'))->assertSessionHas('success', 'SMTP výjimka byla uložena.');
        $u = DB::table('smtp_exceptions')->where('id', $r->id)->first();
        $this->assertSame(["{$this->net}21", 'puvodni', '2020-01-01'], [$u->intip, $u->user, substr($u->datum, 0, 10)], 'zadavatel a datum se nemění');
        $this->as()->get(route('smtp-exceptions.edit', 999999999))->assertNotFound();
        $this->as()->delete(route('smtp-exceptions.destroy', 999999999))->assertNotFound();
    }

    public function test_prava(): void
    {
        $id = (int) DB::table('smtp_exceptions')->insertGetId(['intip' => "{$this->net}9", 'user' => 'c', 'datum' => now()]);
        $this->acl(['edit_all/Network_Controller/smtp_exceptions']);
        $this->assertFalse($this->as()->get(route('smtp-exceptions.index'))->assertOk()->viewData('canEdit'));
        foreach ([route('smtp-exceptions.create'), route('smtp-exceptions.edit', $id)] as $url) {
            $this->as()->get($url)->assertForbidden();
        }
        $this->as()->post(route('smtp-exceptions.store'), ['intip' => "{$this->net}10"])->assertForbidden();
        $this->as()->put(route('smtp-exceptions.update', $id), ['intip' => "{$this->net}10"])->assertForbidden();
        $this->as()->delete(route('smtp-exceptions.destroy', $id))->assertForbidden();
        $this->acl(['view_all/Network_Controller/smtp_exceptions']);
        $this->as()->get(route('smtp-exceptions.index'))->assertForbidden();
        $this->assertTrue(DB::table('smtp_exceptions')->where('id', $id)->exists());
    }
}
