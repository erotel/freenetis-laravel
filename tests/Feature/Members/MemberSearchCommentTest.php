<?php

namespace Tests\Feature\Members;

use App\Models\User;
use App\Services\AclService;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Vyhledávání členů (MemberController::index) hledá i v poznámce (members.comment)
 * — technici si tam píšou MAC/kontakty apod. Stejný vzor jako IČO/DIČ/VS.
 */
class MemberSearchCommentTest extends DatabaseTestCase
{
    public function test_najde_clena_podle_textu_v_poznamce(): void
    {
        $this->mock(AclService::class)->shouldReceive('hasAccess')->andReturnTrue();

        $token = 'ZZPOZNTOKEN' . uniqid();
        $name  = 'SearchCommentTest ' . uniqid();

        $apId = DB::table('members')->whereNotNull('address_point_id')->value('address_point_id');

        $id = DB::table('members')->insertGetId([
            'registration'     => 1,
            'name'             => $name,
            'address_point_id' => $apId,
            'leaving_date'     => '9999-12-31',
            'type'             => 1,
            'comment'          => 'Kontakt/MAC: ' . $token,
        ]);

        $resp = $this->actingAs(User::find(1))
            ->get(route('members.index', ['search' => $token]));

        $resp->assertOk();
        $resp->assertSee($name);

        // Kontrolní: token, který nikde není, člena nevrátí.
        $this->actingAs(User::find(1))
            ->get(route('members.index', ['search' => $token . 'XYZ']))
            ->assertDontSee($name);
    }
}
