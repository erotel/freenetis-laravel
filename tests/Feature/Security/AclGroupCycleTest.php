<?php

namespace Tests\Feature\Security;

use App\Domains\Acl\AclService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Regrese: rodičem skupiny šlo nastavit ji samu nebo její podskupinu.
 * Cyklus v aro_groups.parent_id zacyklil AclService::getGroupHierarchy
 * (počítá se pro všechny skupiny) → visela každá kontrola práv v aplikaci.
 */
class AclGroupCycleTest extends DatabaseTestCase
{
    private int $adminId;
    private int $rootId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = (int) DB::table('users')->where('type', 1)->value('id');
        $this->rootId  = (int) DB::table('aro_groups')->orderBy('id')->value('id');
    }

    private function group(string $name, int $parent): int
    {
        return (int) DB::table('aro_groups')->insertGetId(['name' => $name, 'parent_id' => $parent, 'lft' => 0, 'rgt' => 0, 'value' => 'char_' . uniqid()]);
    }

    private function asAdmin()
    {
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturnTrue();
        $acl->shouldReceive('flushAllCache', 'flushUserCache')->andReturnNull();
        return $this->actingAs(User::find($this->adminId));
    }

    public function test_rodicem_nesmi_byt_skupina_sama_ani_podskupina(): void
    {
        $a  = $this->group('Char A', $this->rootId);
        $b  = $this->group('Char B', $a);
        $c  = $this->group('Char C', $b);
        $msg = 'Nadřazená skupina nemůže být tato skupina ani její podskupina.';

        foreach ([$a, $b, $c] as $parent) {
            $this->asAdmin()->from(route('aro-groups.edit', $a))->put(route('aro-groups.update', $a), ['name' => 'Char A', 'parent_id' => $parent])
                ->assertSessionHasErrors(['parent_id' => $msg]);
        }
        $this->assertSame($this->rootId, (int) DB::table('aro_groups')->where('id', $a)->value('parent_id'));

        $this->asAdmin()->put(route('aro-groups.update', $c), ['name' => 'Char C', 'parent_id' => $a])->assertSessionHasNoErrors();
        $this->assertSame($a, (int) DB::table('aro_groups')->where('id', $c)->value('parent_id'), 'přesun jinam ve stromu jde');

        $offered = $this->asAdmin()->get(route('aro-groups.edit', $a))->viewData('groups')->pluck('id')->map(fn ($i) => (int) $i)->all();
        $this->assertSame([], array_intersect([$a, $b, $c], $offered), 'formulář nenabízí sebe ani podskupiny');
        $this->assertContains($this->rootId, $offered);
    }

    public function test_cyklus_v_datech_nezacykli_vypocet_prav(): void
    {
        $a = $this->group('Char A', $this->rootId);
        $b = $this->group('Char B', $a);
        DB::table('aro_groups')->where('id', $a)->update(['parent_id' => $b]);   // A ↔ B

        $axo = DB::table('axo_map')->join('aco_map', 'aco_map.acl_id', '=', 'axo_map.acl_id')->where('aco_map.value', 'view_all')->first(['axo_map.section_value', 'axo_map.value']);
        $acl = (int) DB::table('acl')->insertGetId(['note' => 'char cyklus']);
        DB::table('aco_map')->insert(['acl_id' => $acl, 'value' => 'view_all']);
        DB::table('axo_map')->insert(['acl_id' => $acl, 'section_value' => $axo->section_value, 'value' => $axo->value]);
        DB::table('aro_groups_map')->insert(['acl_id' => $acl, 'group_id' => $a]);

        $uid = (int) DB::table('users')->insertGetId(['member_id' => (int) DB::table('members')->value('id'), 'login' => 'charcyc_' . uniqid(), 'password' => 'x',
            'type' => 1, 'application_password' => 'x', 'settings' => '', 'name' => 'C', 'surname' => 'C', 'comment' => '']);
        DB::table('groups_aro_map')->insert(['group_id' => $b, 'aro_id' => $uid]);

        $acl = app(AclService::class);
        $this->assertTrue($acl->hasAccess($uid, 'view_all', $axo->section_value, $axo->value), 'B dědí od A (předek v cyklu)');
        $this->assertFalse($acl->hasAccess($uid, 'delete_all', '__char_none', '__char_none'));
    }
}
