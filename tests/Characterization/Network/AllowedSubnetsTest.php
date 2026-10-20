<?php

namespace Tests\Characterization\Network;

use App\Domains\Acl\AclService;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Charakterizace povolených podsítí / přípojných míst (AllowedSubnetController)
 * před přesunem do domény Network: karta člena (admin i vlastník —
 * self-service), přidání s vytlačením nejstarší zapnuté nad limit, přepnutí
 * on/off s vytlačením, limit (vlastní / globální), rychlost místa (zdědění
 * ruší účtování), účtování (jen s vlastní rychlostí, u podepsané smlouvy
 * jen dodatkem), smazání, ACL.
 */
class AllowedSubnetsTest extends DatabaseTestCase
{
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = (int) DB::table('users')->where('type', 1)->value('id');
        Setting::set('allowed_subnets_default_count', 1);
        $this->acl();
    }

    private function acl(array $deny = []): void
    {
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturnUsing(fn ($u, $aco, $section, $value) => (int) $u === $this->adminId && !in_array("{$aco}/{$section}/{$value}", $deny, true));
        $acl->shouldReceive('flushUserCache')->andReturnNull();
    }

    private function as(?int $userId = null)
    {
        return $this->actingAs(User::find($userId ?? $this->adminId));
    }

    /** @return array{member:int, user:int} */
    private function member(): array
    {
        $id = (int) DB::table('members')->insertGetId(['name' => 'Char AS', 'type' => 2, 'registration' => 1, 'locked' => 0,
            'address_point_id' => (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id'),
            'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31']);
        $uid = (int) DB::table('users')->insertGetId(['member_id' => $id, 'login' => 'charas_' . uniqid(), 'password' => Hash::make('x'), 'type' => 1,
            'application_password' => 'x', 'settings' => '', 'name' => 'C', 'surname' => 'A', 'comment' => '']);
        return ['member' => $id, 'user' => $uid];
    }

    private function subnet(string $name = 'char'): int
    {
        return (int) DB::table('subnets')->insertGetId(['name' => $name . ' ' . uniqid(), 'network_address' => '10.249.' . random_int(1, 250) . '.0', 'netmask' => '255.255.255.0']);
    }

    private function allowed(int $member, int $subnet, bool $enabled = true, array $extra = []): int
    {
        return (int) DB::table('allowed_subnets')->insertGetId($extra + ['member_id' => $member, 'subnet_id' => $subnet, 'enabled' => $enabled ? 1 : 0, 'charged' => 0]);
    }

    /** @return array<int, bool> subnet_id => enabled */
    private function state(int $member): array
    {
        return DB::table('allowed_subnets')->where('member_id', $member)->orderBy('id')->pluck('enabled', 'subnet_id')
            ->map(fn ($e) => (bool) $e)->all();
    }

    private function speed(): int
    {
        return (int) DB::table('speed_classes')->insertGetId(['name' => 'char ' . uniqid(), 'd_ceil' => 1, 'd_rate' => 1, 'u_ceil' => 1, 'u_rate' => 1, 'price' => 100]);
    }

    // ── karta ────────────────────────────────────────────────────────────────

    public function test_karta_admin_a_vlastnik(): void
    {
        $x = $this->member();
        $s1 = $this->subnet();
        $s2 = $this->subnet();
        $a2 = $this->allowed($x['member'], $s2, false);
        $a1 = $this->allowed($x['member'], $s1);

        $res = $this->as()->get(route('allowed_subnets.by_member', $x['member']))->assertOk()->assertViewIs('allowed_subnets.show_by_member');
        $this->assertSame([$a2, $a1], $res->viewData('allowedSubnets')->pluck('id')->map(fn ($i) => (int) $i)->all(), 'podle id');
        $this->assertNotContains($s1, $res->viewData('availableSubnets')->pluck('id')->all());
        $this->assertSame(DB::table('subnets')->count() - 2, $res->viewData('availableSubnets')->count());
        $this->assertSame([1, false, 1], [$res->viewData('maxCount'), $res->viewData('hasOwnLimit'), $res->viewData('globalCount')]);
        $this->assertTrue($res->viewData('canToggle') && $res->viewData('canEditCount') && $res->viewData('canNew') && $res->viewData('canDelete')
            && $res->viewData('canEditSpeed') && $res->viewData('canViewSubnetDetail'));
        $this->assertSame(DB::table('speed_classes')->count(), $res->viewData('speedClasses')->count());

        DB::table('allowed_subnets_counts')->insert(['member_id' => $x['member'], 'count' => 3]);
        $own = $this->as($x['user'])->get(route('allowed_subnets.by_member', $x['member']))->assertOk();
        $this->assertSame([3, true, true, false, false, false], [$own->viewData('maxCount'), $own->viewData('hasOwnLimit'), $own->viewData('canToggle'),
            $own->viewData('canEditCount'), $own->viewData('canNew'), $own->viewData('canViewSubnetDetail')]);
        $this->assertSame([0, 0], [$own->viewData('availableSubnets')->count(), $own->viewData('speedClasses')->count()], 'člen nabídku nedostane');

        $y = $this->member();
        $this->as($y['user'])->get(route('allowed_subnets.by_member', $x['member']))->assertForbidden();
        $this->as()->get(route('allowed_subnets.by_member', 999999999))->assertNotFound();
    }

    // ── přidání, přepnutí, limit ─────────────────────────────────────────────

    public function test_pridani_nad_limit_vytlaci_nejstarsi(): void
    {
        $x = $this->member();
        DB::table('allowed_subnets_counts')->insert(['member_id' => $x['member'], 'count' => 2]);
        [$s1, $s2, $s3] = [$this->subnet(), $this->subnet(), $this->subnet()];

        foreach ([$s1, $s2] as $s) {
            $this->as()->post(route('allowed_subnets.store', $x['member']), ['subnet_id' => $s])
                ->assertRedirect(route('allowed_subnets.by_member', $x['member']))->assertSessionHas('success', 'Podsíť byla přidána.');
        }
        $this->assertSame([$s1 => true, $s2 => true], $this->state($x['member']));
        $this->as()->post(route('allowed_subnets.store', $x['member']), ['subnet_id' => $s3]);
        $this->assertSame([$s1 => false, $s2 => true, $s3 => true], $this->state($x['member']));

        $this->as()->post(route('allowed_subnets.store', $x['member']), ['subnet_id' => $s1])->assertSessionHasErrors(['subnet_id' => 'Tato podsíť je již v seznamu povolených.']);
        $this->as()->post(route('allowed_subnets.store', $x['member']), ['subnet_id' => 999999999])->assertSessionHasErrors('subnet_id');
        $this->as()->post(route('allowed_subnets.store', 999999999), ['subnet_id' => $s1])->assertNotFound();

        // limit 0 = neomezeno
        DB::table('allowed_subnets_counts')->where('member_id', $x['member'])->update(['count' => 0]);
        $s4 = $this->subnet();
        $this->as()->post(route('allowed_subnets.store', $x['member']), ['subnet_id' => $s4]);
        $this->assertSame([$s1 => false, $s2 => true, $s3 => true, $s4 => true], $this->state($x['member']));
    }

    public function test_prepnuti_vlastnikem_a_vytlaceni(): void
    {
        $x = $this->member();                    // bez vlastního limitu → globální 1
        [$s1, $s2] = [$this->subnet(), $this->subnet()];
        $a1 = $this->allowed($x['member'], $s1);
        $a2 = $this->allowed($x['member'], $s2, false);

        $this->as($x['user'])->post(route('allowed_subnets.toggle', $a2))
            ->assertRedirect(route('allowed_subnets.by_member', $x['member']))->assertSessionHas('success', 'Podsíť byla zapnuta.');
        $this->assertSame([$s1 => false, $s2 => true], $this->state($x['member']), 'zapnutí na limitu vytlačí nejstarší');
        $this->as($x['user'])->post(route('allowed_subnets.toggle', $a2))->assertSessionHas('success', 'Podsíť byla vypnuta.');
        $this->assertSame([$s1 => false, $s2 => false], $this->state($x['member']));

        $y = $this->member();
        $this->as($y['user'])->post(route('allowed_subnets.toggle', $a1))->assertForbidden();
        $this->as()->post(route('allowed_subnets.toggle', 999999999))->assertNotFound();
    }

    public function test_limit(): void
    {
        $x = $this->member();
        $this->as()->from(route('allowed_subnets.by_member', $x['member']))->put(route('allowed_subnets.update_count', $x['member']), ['allowed_subnets_count' => 4])
            ->assertRedirect(route('allowed_subnets.by_member', $x['member']))->assertSessionHas('success', 'Maximum povolených podsítí bylo uloženo.');
        $this->assertSame(4, (int) DB::table('allowed_subnets_counts')->where('member_id', $x['member'])->value('count'));
        $this->as()->put(route('allowed_subnets.update_count', $x['member']), []);
        $this->assertSame(0, (int) DB::table('allowed_subnets_counts')->where('member_id', $x['member'])->value('count'), 'prázdné = 0');
        $this->as()->put(route('allowed_subnets.update_count', $x['member']), ['use_global' => '1'])->assertSessionHas('success', 'Limit vrácen na globální default.');
        $this->assertFalse(DB::table('allowed_subnets_counts')->where('member_id', $x['member'])->exists());
        $this->as()->put(route('allowed_subnets.update_count', $x['member']), ['allowed_subnets_count' => -1])->assertSessionHasErrors('allowed_subnets_count');
        $this->as()->put(route('allowed_subnets.update_count', 999999999), ['allowed_subnets_count' => 1])->assertNotFound();
    }

    // ── rychlost a účtování ──────────────────────────────────────────────────

    public function test_rychlost_a_uctovani(): void
    {
        $x = $this->member();
        $a = $this->allowed($x['member'], $this->subnet());
        $sc = $this->speed();
        $row = fn () => DB::table('allowed_subnets')->where('id', $a)->first();

        $this->as()->put(route('allowed_subnets.update_billing', $a), ['charged' => 1])
            ->assertRedirect(route('allowed_subnets.by_member', $x['member']))
            ->assertSessionHas('error', 'Účtovat lze jen místo s vlastní rychlostí. Nejprve mu nastavte rychlost (ne „zdědit").');
        $this->as()->put(route('allowed_subnets.update_speed', $a), ['speed_class_id' => $sc])
            ->assertSessionHas('success', 'Rychlost přípojného místa byla nastavena.');
        $this->as()->put(route('allowed_subnets.update_billing', $a), ['charged' => 1])->assertSessionHas('success', 'Přípojné místo se nově účtuje (cena podle rychlosti).');
        $this->assertSame([$sc, 1], [(int) $row()->speed_class_id, (int) $row()->charged]);

        $this->as()->put(route('allowed_subnets.update_speed', $a), ['speed_class_id' => ''])
            ->assertSessionHas('success', 'Rychlost přípojného místa se nově dědí od člena (účtování zrušeno).');
        $this->assertSame([null, 0], [$row()->speed_class_id, (int) $row()->charged]);
        $this->as()->put(route('allowed_subnets.update_speed', $a), ['speed_class_id' => 999999999])->assertSessionHasErrors('speed_class_id');

        $this->as()->put(route('allowed_subnets.update_speed', $a), ['speed_class_id' => $sc]);
        $this->as()->put(route('allowed_subnets.update_billing', $a), ['charged' => 1]);
        $this->as()->put(route('allowed_subnets.update_billing', $a), [])->assertSessionHas('success', 'Přípojné místo se nově neúčtuje.');
        $this->assertSame(0, (int) $row()->charged);
        $this->as()->put(route('allowed_subnets.update_billing', 999999999), ['charged' => 1])->assertNotFound();
    }

    public function test_uctovani_u_podepsane_smlouvy_jen_dodatkem(): void
    {
        $svc = $this->mock(\App\Domains\Contracts\Repositories\ContractRepository::class);
        $x = $this->member();
        $svc->shouldReceive('forMember')->with($x['member'])->andReturn(new \App\Models\Contract(['status' => 'signed']));
        $a = $this->allowed($x['member'], $this->subnet(), true, ['speed_class_id' => $this->speed()]);

        $this->as()->put(route('allowed_subnets.update_billing', $a), ['charged' => 1])
            ->assertRedirect(route('contracts.show', $x['member']))
            ->assertSessionHas('error', 'Zpoplatnění přípojného místa u člena s podepsanou smlouvou zapni dodatkem ke smlouvě (začne účtovat po podpisu), ne přímo v podsítích.');
        $this->assertSame(0, (int) DB::table('allowed_subnets')->where('id', $a)->value('charged'));
    }

    // ── smazání a ACL ────────────────────────────────────────────────────────

    public function test_smazani_a_prava(): void
    {
        $x = $this->member();
        $a = $this->allowed($x['member'], $this->subnet());

        $this->acl(['new_all/Allowed_subnets_Controller/allowed_subnet', 'edit_all/Allowed_subnets_Controller/allowed_subnet', 'delete_all/Allowed_subnets_Controller/allowed_subnet']);
        $this->as()->post(route('allowed_subnets.store', $x['member']), ['subnet_id' => $this->subnet()])->assertForbidden();
        $this->as()->put(route('allowed_subnets.update_count', $x['member']), ['allowed_subnets_count' => 1])->assertForbidden();
        $this->as()->put(route('allowed_subnets.update_speed', $a), [])->assertForbidden();
        $this->as()->put(route('allowed_subnets.update_billing', $a), [])->assertForbidden();
        $this->as()->post(route('allowed_subnets.toggle', $a))->assertForbidden();
        $this->as()->delete(route('allowed_subnets.destroy', $a))->assertForbidden();
        $this->as($x['user'])->delete(route('allowed_subnets.destroy', $a))->assertForbidden();
        $this->as($x['user'])->put(route('allowed_subnets.update_speed', $a), [])->assertForbidden();
        $this->as()->put(route('allowed_subnets.update_speed', 999999999), [])->assertNotFound('404 dřív než ACL');

        $this->acl();
        $this->as()->delete(route('allowed_subnets.destroy', $a))
            ->assertRedirect(route('allowed_subnets.by_member', $x['member']))->assertSessionHas('success', 'Podsíť byla odebrána.');
        $this->assertFalse(DB::table('allowed_subnets')->where('id', $a)->exists());
        $this->as()->delete(route('allowed_subnets.destroy', 999999999))->assertNotFound();
    }
}
