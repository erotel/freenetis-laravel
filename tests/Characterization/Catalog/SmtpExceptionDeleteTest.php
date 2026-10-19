<?php

namespace Tests\Characterization\Catalog;

use Illuminate\Support\Facades\DB;

/**
 * Regrese: SMTP výjimka se mazala přes GET …/delete (bez CSRF — stačil
 * podvržený odkaz nebo <img> u přihlášeného admina). Teď jen DELETE.
 */
class SmtpExceptionDeleteTest extends CatalogTestCase
{
    public function test_mazani_jen_delete(): void
    {
        $id = (int) DB::table('smtp_exceptions')->insertGetId(['intip' => '10.241.1.' . random_int(1, 250), 'user' => 'char', 'datum' => now()]);
        $this->as()->get(route('smtp-exceptions.index'))->assertOk()->assertSee('name="_method" value="DELETE"', false);
        $this->as()->get("/smtp-exceptions/{$id}/delete")->assertNotFound();
        $this->assertTrue(DB::table('smtp_exceptions')->where('id', $id)->exists());
        $this->as()->delete(route('smtp-exceptions.destroy', $id))->assertRedirect(route('smtp-exceptions.index'))->assertSessionHas('success', 'SMTP výjimka byla smazána.');
        $this->assertFalse(DB::table('smtp_exceptions')->where('id', $id)->exists());
    }
}
