<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Services\AclService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Vazba members↔users je „ujetá" (id se rozešla). Globální hledání proto musí
 * umět najít uživatele i podle jeho user id a to id ukázat, aby šlo výsledky
 * rozlišit. Regrese konzultace 2026-08-14.
 */
class SearchByUserIdTest extends DatabaseTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturn(true);

        $memberId = (int) DB::table('members')
            ->where('locked', 0)->where('type', 2)->where('leaving_date', '9999-12-31')
            ->value('id');

        $this->userId = (int) DB::table('users')->insertGetId([
            'member_id'            => $memberId,
            'login'               => 'searchid_' . uniqid(),
            'password'            => Hash::make('x'),
            'type'                => 1,
            'application_password'=> 'xxxxxxxx',
            'settings'            => '',
            'name'                => 'Search', 'surname' => 'Idcek', 'comment' => '',
        ]);
    }

    public function test_ajax_najde_uzivatele_podle_user_id(): void
    {
        $r = $this->actingAs(User::find($this->userId))
            ->getJson(route('search.ajax', ['q' => (string) $this->userId]));

        $r->assertOk();
        // Ve výsledcích je uživatel a jeho id je v titulku (#id).
        $r->assertJsonFragment(['title' => 'Uživatel ' . User::find($this->userId)->login . ' (#' . $this->userId . ')']);
    }

    public function test_stranka_hledani_zobrazi_user_id(): void
    {
        $r = $this->actingAs(User::find($this->userId))
            ->get(route('search', ['q' => (string) $this->userId]));

        $r->assertOk();
        $r->assertSee('User ID');          // hlavička sloupce
        $r->assertSee((string) $this->userId);
    }
}
