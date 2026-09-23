<?php

namespace Tests\Feature\Members;

use App\Helpers\MemberType;
use App\Models\SpeedClass;
use App\Models\User;
use App\Services\AclService;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Řádný člen (typ 90) nesmí mít přiřazenou třídu rychlosti (tarif) — server to
 * vynutí i kdyby se speed_class_id podvrhlo POSTem. Jiný typ si rychlost drží.
 */
class MemberType90NoSpeedTest extends DatabaseTestCase
{
    private int $mid;
    private int $scId;
    private int $uid;
    private int $otherType;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mid  = (int) DB::table('members')->where('type', 90)->value('id');
        $this->scId = (int) SpeedClass::value('id');
        $this->uid  = (int) DB::table('users')->where('type', 1)->value('id');
        if (!$this->mid || !$this->scId || !$this->uid) {
            $this->markTestSkipped('chybí typ90 člen / speed_class / user');
        }
        $this->otherType = (int) collect(array_keys(MemberType::labels()))
            ->first(fn ($k) => (int) $k !== 90, 2);

        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturnTrue();
        $acl->shouldReceive('flushUserCache')->andReturnNull();
    }

    private function putType(int $type, ?int $sc)
    {
        $name = DB::table('members')->where('id', $this->mid)->value('name') ?: 'Test';
        return $this->actingAs(User::find($this->uid))
            ->put(route('members.update', $this->mid), [
                'name'           => $name,
                'type'           => $type,
                'speed_class_id' => $sc,
            ]);
    }

    private function speed(): ?int
    {
        $v = DB::table('members')->where('id', $this->mid)->value('speed_class_id');
        return $v === null ? null : (int) $v;
    }

    public function test_typ90_nesmi_dostat_rychlost(): void
    {
        $this->putType(90, $this->scId);
        $this->assertNull($this->speed(), 'typ 90 nesmí mít speed_class ani při podvržení POSTem');
    }

    public function test_jiny_typ_rychlost_zustane(): void
    {
        $this->putType($this->otherType, $this->scId);
        $this->assertSame($this->scId, $this->speed(), 'ne-90 typ si rychlost nastaví normálně');
    }
}
