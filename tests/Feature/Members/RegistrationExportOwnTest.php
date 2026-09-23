<?php

namespace Tests\Feature\Members;

use App\Models\User;
use App\Services\AclService;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Self-service export přihlášky pro řádné členy (typ 90):
 *  - view_own → člen smí stáhnout JEN svoji přihlášku (type=registration),
 *  - NESMÍ cizí člena ani jiný typ (ukončení/výpověď) — to zůstává staff (view_all),
 *  - staff (view_all) může vše.
 */
class RegistrationExportOwnTest extends DatabaseTestCase
{
    private object $a; // vlastní člen (mid + uid)
    private int $bMid; // jiný člen

    protected function setUp(): void
    {
        parent::setUp();
        $m = DB::table('members as m')->join('users as u', 'u.member_id', '=', 'm.id')
            ->where('m.type', 90)->where('u.type', 1)
            ->select('m.id as mid', 'u.id as uid')->orderBy('m.id')->limit(2)->get();
        if ($m->count() < 2) {
            $this->markTestSkipped('nejsou 2 řádní členové s hlavním uživatelem');
        }
        $this->a    = $m[0];
        $this->bMid = (int) $m[1]->mid;
    }

    /** Mock ACL: nastav view_all/view_own pro registration_export, ostatní práva povol. */
    private function mockAcl(bool $all, bool $own): void
    {
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('flushUserCache')->andReturnNull();
        $acl->shouldReceive('hasAccess')->andReturnUsing(
            function ($uid, $aco, $sec, $val) use ($all, $own) {
                if ($sec === 'Members_Controller' && $val === 'registration_export') {
                    return $aco === 'view_all' ? $all : ($aco === 'view_own' ? $own : false);
                }
                return true;
            }
        );
    }

    private function getExport(int $mid, string $type)
    {
        return $this->actingAs(User::find($this->a->uid))
            ->get("members/{$mid}/registration-export/{$type}");
    }

    public function test_clen_nesmi_cizi_prihlasku(): void
    {
        $this->mockAcl(all: false, own: true);
        $this->getExport($this->bMid, 'registration')->assertForbidden(); // 403
    }

    public function test_clen_nesmi_ukonceni_ani_sve(): void
    {
        $this->mockAcl(all: false, own: true);
        $this->getExport((int) $this->a->mid, 'end')->assertForbidden(); // jen 'registration'
        $this->getExport((int) $this->a->mid, 'contract_end')->assertForbidden();
    }

    public function test_clen_smi_svoji_prihlasku(): void
    {
        $this->mockAcl(all: false, own: true);
        $r = $this->getExport((int) $this->a->mid, 'registration');
        $this->assertNotSame(403, $r->status(), 'vlastní přihlášku smí (ne 403)');
    }

    public function test_staff_smi_cizi(): void
    {
        $this->mockAcl(all: true, own: false);
        $r = $this->getExport($this->bMid, 'registration');
        $this->assertNotSame(403, $r->status(), 'staff (view_all) smí i cizího');
    }
}
