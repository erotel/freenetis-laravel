<?php

namespace Tests\Characterization\Acl;

use App\Models\User;
use App\Services\AclService;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Charakterizace správy ACL (AroGroupController) před přesunem do domény:
 * strom skupin, karta skupiny (pravidla, uživatelé, MFA), CRUD skupin
 * (mazání jen bez podskupin / uživatelů), členství uživatelů, přiřazení
 * pravidel, CRUD pravidel (aco_map / axo_map / aro_groups_map), audit
 * a vyprázdnění ACL cache. Vyhodnocení práv: AclService (beze změny).
 */
class AclAdminTest extends DatabaseTestCase
{
    private int $adminId;
    private int $rootId;
    /** @var string[] volání flush* na AclService */
    private array $flushes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = (int) DB::table('users')->where('type', 1)->value('id');
        $this->rootId  = (int) DB::table('aro_groups')->orderBy('id')->value('id');
        $this->acl();
    }

    private function acl(array $deny = []): void
    {
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturnUsing(fn ($u, $aco, $section, $value) => !in_array("{$aco}/{$section}/{$value}", $deny, true));
        $acl->shouldReceive('flushUserCache')->andReturnUsing(function ($uid) { $this->flushes[] = 'user:' . $uid; });
        $acl->shouldReceive('flushAllCache')->andReturnUsing(function () { $this->flushes[] = 'all'; });
    }

    private function as()
    {
        return $this->actingAs(User::find($this->adminId));
    }

    private function group(string $name, ?int $parent = null): int
    {
        return (int) DB::table('aro_groups')->insertGetId(['name' => $name, 'parent_id' => $parent ?? $this->rootId, 'lft' => 0, 'rgt' => 0, 'value' => 'char_' . uniqid()]);
    }

    private function user(): int
    {
        $member = (int) DB::table('members')->value('id');
        return (int) DB::table('users')->insertGetId(['member_id' => $member, 'login' => 'characl_' . uniqid(), 'password' => 'x', 'type' => 1,
            'application_password' => 'x', 'settings' => '', 'name' => 'C', 'surname' => 'A', 'comment' => '']);
    }

    /** @return int[] axo id */
    private function axos(int $n): array
    {
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $id = (int) DB::table('axo')->max('id') + 1;   // axo.id bez auto-increment
            DB::table('axo')->insert(['id' => $id, 'section_value' => 'Char_Controller', 'value' => 'res' . $i . '_' . uniqid(), 'name' => 'Char ' . $i]);
            $ids[] = $id;
        }
        return $ids;
    }

    private function lastAudit(string $table): ?object
    {
        return DB::table('audit_logs')->where('auditable_type', $table)->orderByDesc('id')->first();
    }

    // ── strom a karta ────────────────────────────────────────────────────────

    public function test_strom_skupin(): void
    {
        $a = $this->group('Char A');
        $b = $this->group('Char B', $a);

        $res = $this->as()->get(route('aro-groups.index'))->assertOk()->assertViewIs('aro_groups.index');
        $find = function (array $nodes, int $id) use (&$find) {
            foreach ($nodes as $n) {
                if ((int) $n['group']->id === $id) return $n;
                if ($x = $find($n['children'], $id)) return $x;
            }
            return null;
        };
        $na = $find($res->viewData('tree'), $a);
        $this->assertSame([$b], array_map(fn ($c) => (int) $c['group']->id, $na['children']));
        $this->assertSame($na['depth'] + 1, $na['children'][0]['depth']);
        $this->assertSame(DB::table('aro_groups')->count(), $res->viewData('groups')->count());

        $this->acl(['view_all/Aro_groups_Controller/aro_group']);
        $this->as()->get(route('aro-groups.index'))->assertForbidden();
    }

    public function test_karta_skupiny(): void
    {
        $g = $this->group('Char G');
        $child = $this->group('Char G1', $g);
        $u = $this->user();
        DB::table('groups_aro_map')->insert(['group_id' => $g, 'aro_id' => $u]);
        [$x1, $x2] = $this->axos(2);
        $acl = (int) DB::table('acl')->insertGetId(['note' => 'char pravidlo']);
        foreach ([$x1, $x2] as $x) {
            $axo = DB::table('axo')->where('id', $x)->first();
            DB::table('axo_map')->insert(['acl_id' => $acl, 'section_value' => $axo->section_value, 'value' => $axo->value]);
        }
        DB::table('aro_groups_map')->insert(['acl_id' => $acl, 'group_id' => $g]);
        DB::table('mfa_required_groups')->insert(['group_id' => $g, 'created_at' => now()]);

        $res = $this->as()->get(route('aro-groups.show', $g))->assertOk()->assertViewIs('aro_groups.show');
        $this->assertSame($g, (int) $res->viewData('group')->id);
        $this->assertSame([$child], $res->viewData('group')->children->pluck('id')->map(fn ($i) => (int) $i)->all());
        $this->assertSame([$acl], $res->viewData('aclRules')->keys()->map(fn ($i) => (int) $i)->all());
        $rows = $res->viewData('aclRules')[$acl];
        $this->assertSame(['acl_id', 'note', 'section', 'resource'], array_keys((array) $rows->first()));
        $this->assertSame($rows->pluck('resource')->sort()->values()->all(), $rows->pluck('resource')->all());
        $this->assertSame([$u], $res->viewData('users')->pluck('id')->map(fn ($i) => (int) $i)->all());
        $this->assertNotContains($u, $res->viewData('allUsers')->pluck('id')->map(fn ($i) => (int) $i)->all());
        $this->assertSame(DB::table('users')->count() - 1, $res->viewData('allUsers')->count());
        $this->assertNotContains($acl, $res->viewData('allAcls')->pluck('id')->map(fn ($i) => (int) $i)->all());
        $this->assertSame(DB::table('acl')->count() - 1, $res->viewData('allAcls')->count());
        $this->assertTrue($res->viewData('mfaRequired'));
        $this->assertTrue($res->viewData('canEdit'));

        $this->as()->get(route('aro-groups.show', 999999999))->assertNotFound();
        $this->acl(['edit_all/Aro_groups_Controller/aro_group']);
        $this->assertFalse($this->as()->get(route('aro-groups.show', $g))->viewData('canEdit'));
        $this->acl(['view_all/Aro_groups_Controller/aro_group']);
        $this->as()->get(route('aro-groups.show', $g))->assertForbidden();
    }

    // ── CRUD skupin ──────────────────────────────────────────────────────────

    public function test_vytvoreni_uprava_smazani_skupiny(): void
    {
        $this->as()->get(route('aro-groups.create'))->assertOk()->assertViewHas('groups');
        $this->as()->post(route('aro-groups.store'), ['name' => 'Char Nova Skupina', 'parent_id' => $this->rootId])
            ->assertRedirect(route('aro-groups.index'))->assertSessionHas('success', 'Skupina byla vytvořena.');
        $g = DB::table('aro_groups')->where('name', 'Char Nova Skupina')->first();
        $this->assertSame([$this->rootId, 0, 0, 'char_nova_skupina'], [(int) $g->parent_id, (int) $g->lft, (int) $g->rgt, $g->value]);
        $this->as()->post(route('aro-groups.store'), ['name' => '', 'parent_id' => 999999999])->assertSessionHasErrors(['name', 'parent_id']);

        $edit = $this->as()->get(route('aro-groups.edit', $g->id))->assertOk();
        $this->assertNotContains((int) $g->id, $edit->viewData('groups')->pluck('id')->map(fn ($i) => (int) $i)->all(), 'sama sebe nenabízí');

        $other = $this->group('Char Rodic');
        $this->flushes = [];
        $this->as()->put(route('aro-groups.update', $g->id), ['name' => 'Char Prejmenovana', 'parent_id' => $other])
            ->assertRedirect(route('aro-groups.show', $g->id))->assertSessionHas('success', 'Skupina byla upravena.');
        $this->assertSame(['Char Prejmenovana', $other, 'char_nova_skupina'], [DB::table('aro_groups')->where('id', $g->id)->value('name'),
            (int) DB::table('aro_groups')->where('id', $g->id)->value('parent_id'), DB::table('aro_groups')->where('id', $g->id)->value('value')]);
        $this->assertSame(['all'], $this->flushes);
        $this->as()->put(route('aro-groups.update', 999999999), ['name' => 'x', 'parent_id' => $other])->assertNotFound();

        // mazání: s podskupinou / uživatelem ne
        $this->as()->from(route('aro-groups.show', $other))->delete(route('aro-groups.destroy', $other))
            ->assertRedirect(route('aro-groups.show', $other))->assertSessionHas('error', 'Nelze smazat skupinu, která má podskupiny.');
        $u = $this->user();
        DB::table('groups_aro_map')->insert(['group_id' => $g->id, 'aro_id' => $u]);
        $this->as()->from(route('aro-groups.show', $g->id))->delete(route('aro-groups.destroy', $g->id))
            ->assertSessionHas('error', 'Nelze smazat skupinu, která má přiřazené uživatele.');
        DB::table('groups_aro_map')->where('group_id', $g->id)->delete();

        $acl = (int) DB::table('acl')->insertGetId(['note' => 'char']);
        DB::table('aro_groups_map')->insert(['acl_id' => $acl, 'group_id' => $g->id]);
        $this->flushes = [];
        $this->as()->delete(route('aro-groups.destroy', $g->id))->assertRedirect(route('aro-groups.index'))->assertSessionHas('success', 'Skupina byla smazána.');
        $this->assertFalse(DB::table('aro_groups')->where('id', $g->id)->exists());
        $this->assertFalse(DB::table('aro_groups_map')->where('group_id', $g->id)->exists(), 'přiřazení pravidel pryč');
        $this->assertTrue(DB::table('acl')->where('id', $acl)->exists(), 'pravidlo zůstává');
        $this->assertSame(['all'], $this->flushes);
        $this->as()->delete(route('aro-groups.destroy', 999999999))->assertNotFound();
    }

    // ── uživatelé a pravidla skupiny ─────────────────────────────────────────

    public function test_clenstvi_uzivatelu(): void
    {
        $g = $this->group('Char G');
        $u = $this->user();

        $this->as()->post(route('aro-groups.add-user', $g), ['user_id' => $u])
            ->assertRedirect(route('aro-groups.show', $g))->assertSessionHas('success', 'Uživatel byl přidán do skupiny.');
        $this->assertSame(1, DB::table('groups_aro_map')->where(['group_id' => $g, 'aro_id' => $u])->count());
        $a = $this->lastAudit('groups_aro_map');
        $this->assertSame(['created', $g, ['group_id' => $g, 'user_id' => $u]], [$a->action, (int) $a->auditable_id, json_decode($a->new_values, true)]);
        $this->assertSame(['user:' . $u], $this->flushes);

        $before = (int) DB::table('audit_logs')->max('id');
        $this->as()->post(route('aro-groups.add-user', $g), ['user_id' => $u])->assertSessionHas('success');
        $this->assertSame(1, DB::table('groups_aro_map')->where(['group_id' => $g, 'aro_id' => $u])->count(), 'podruhé nic');
        $this->assertSame($before, (int) DB::table('audit_logs')->max('id'));
        $this->as()->post(route('aro-groups.add-user', $g), ['user_id' => 999999999])->assertSessionHasErrors('user_id');

        $this->flushes = [];
        $this->as()->delete(route('aro-groups.remove-user', [$g, $u]))
            ->assertRedirect(route('aro-groups.show', $g))->assertSessionHas('success', 'Uživatel byl odebrán ze skupiny.');
        $this->assertFalse(DB::table('groups_aro_map')->where(['group_id' => $g, 'aro_id' => $u])->exists());
        $a = $this->lastAudit('groups_aro_map');
        $this->assertSame(['deleted', ['group_id' => $g, 'user_id' => $u]], [$a->action, json_decode($a->old_values, true)]);
        $this->assertSame(['user:' . $u], $this->flushes);
    }

    public function test_pravidla_skupiny_a_mfa(): void
    {
        $g = $this->group('Char G');
        $acl = (int) DB::table('acl')->insertGetId(['note' => 'char']);

        $this->as()->post(route('aro-groups.add-acl', $g), ['acl_id' => $acl])
            ->assertRedirect(route('aro-groups.show', $g))->assertSessionHas('success', 'ACL pravidlo bylo přiřazeno skupině.');
        $this->assertTrue(DB::table('aro_groups_map')->where(['group_id' => $g, 'acl_id' => $acl])->exists());
        $a = $this->lastAudit('aro_groups_map');
        $this->assertSame(['created', ['group_id' => $g, 'acl_id' => $acl]], [$a->action, json_decode($a->new_values, true)]);
        $this->assertSame(['all'], $this->flushes);
        $this->flushes = [];
        $this->as()->post(route('aro-groups.add-acl', $g), ['acl_id' => $acl]);
        $this->assertSame([[], 1], [$this->flushes, DB::table('aro_groups_map')->where(['group_id' => $g, 'acl_id' => $acl])->count()]);
        $this->as()->post(route('aro-groups.add-acl', $g), ['acl_id' => 999999999])->assertSessionHasErrors('acl_id');

        $this->as()->delete(route('aro-groups.remove-acl', [$g, $acl]))->assertSessionHas('success', 'ACL pravidlo bylo odebráno skupině.');
        $this->assertFalse(DB::table('aro_groups_map')->where(['group_id' => $g, 'acl_id' => $acl])->exists());
        $this->assertSame(['deleted', ['group_id' => $g, 'acl_id' => $acl]], [$this->lastAudit('aro_groups_map')->action, json_decode($this->lastAudit('aro_groups_map')->old_values, true)]);
        $this->assertSame(['all'], $this->flushes);

        $this->as()->post(route('aro-groups.mfa-required', $g), ['required' => '1'])
            ->assertRedirect(route('aro-groups.show', $g))
            ->assertSessionHas('success', 'Skupina teď vyžaduje dvoufázové přihlášení. Členové budou při dalším přihlášení vyzváni k nastavení.');
        $this->assertTrue(DB::table('mfa_required_groups')->where('group_id', $g)->exists());
        $a = $this->lastAudit('mfa_required_groups');
        $this->assertSame(['updated', $g, ['group_id' => $g, 'required' => true]], [$a->action, (int) $a->auditable_id, json_decode($a->new_values, true)]);
        $this->as()->post(route('aro-groups.mfa-required', $g), [])->assertSessionHas('success', 'Povinné dvoufázové přihlášení pro skupinu vypnuto.');
        $this->assertFalse(DB::table('mfa_required_groups')->where('group_id', $g)->exists());
        $this->as()->post(route('aro-groups.mfa-required', 999999999), ['required' => '1'])->assertNotFound();
    }

    // ── CRUD pravidel ────────────────────────────────────────────────────────

    public function test_pravidla_vytvoreni_uprava_smazani(): void
    {
        [$x1, $x2, $x3] = $this->axos(3);
        $g1 = $this->group('Char G1');
        $g2 = $this->group('Char G2');

        $form = $this->as()->get(route('acl.create'))->assertOk()->assertViewIs('aro_groups.acl_form');
        $this->assertSame(['view_all', 'edit_all', 'new_all', 'delete_all', 'view_own', 'edit_own', 'new_own', 'delete_own'], $form->viewData('actions'));
        $this->assertNull($form->viewData('acl'));
        $this->assertSame([], $form->viewData('selected'));
        $this->assertSame(DB::table('axo')->count(), $form->viewData('axo')->count());

        $this->as()->post(route('acl.store'), ['note' => 'Char pravidlo', 'actions' => ['view_all', 'edit_all'], 'axo_ids' => [$x1, $x2], 'group_ids' => [$g1]])
            ->assertRedirect(route('aro-groups.index'))->assertSessionHas('success', 'ACL pravidlo bylo vytvořeno.');
        $acl = (int) DB::table('acl')->where('note', 'Char pravidlo')->value('id');
        $axoVal = fn ($id) => DB::table('axo')->where('id', $id)->value('value');
        $this->assertEqualsCanonicalizing(['view_all', 'edit_all'], DB::table('aco_map')->where('acl_id', $acl)->pluck('value')->all());
        $this->assertEqualsCanonicalizing([$axoVal($x1), $axoVal($x2)], DB::table('axo_map')->where('acl_id', $acl)->pluck('value')->all());
        $this->assertSame([$g1], DB::table('aro_groups_map')->where('acl_id', $acl)->pluck('group_id')->map(fn ($i) => (int) $i)->all());
        $a = $this->lastAudit('acl');
        $this->assertSame(['created', $acl, ['note' => 'Char pravidlo', 'actions' => ['view_all', 'edit_all'], 'axo_ids' => [$x1, $x2], 'group_ids' => [$g1]]],
            [$a->action, (int) $a->auditable_id, json_decode($a->new_values, true)]);
        $this->assertSame(['all'], $this->flushes);
        $this->as()->post(route('acl.store'), ['note' => '', 'actions' => [], 'axo_ids' => [999999999]])->assertSessionHasErrors(['note', 'actions', 'axo_ids.0']);

        $edit = $this->as()->get(route('acl.edit', $acl))->assertOk();
        $sel = $edit->viewData('selected');
        $this->assertEqualsCanonicalizing(['view_all', 'edit_all'], $sel['actions']);
        $this->assertEqualsCanonicalizing([$x1, $x2], array_map('intval', $sel['axo_ids']));
        $this->assertSame([$g1], array_map('intval', $sel['group_ids']));
        $this->as()->get(route('acl.edit', 999999999))->assertNotFound();

        $this->flushes = [];
        $this->as()->put(route('acl.update', $acl), ['note' => 'Char upraveno', 'actions' => ['view_own'], 'axo_ids' => [$x3], 'group_ids' => [$g2]])
            ->assertRedirect(route('aro-groups.index'))->assertSessionHas('success', 'ACL pravidlo bylo upraveno.');
        $this->assertSame(['Char upraveno', ['view_own'], [$axoVal($x3)], [$g2]], [DB::table('acl')->where('id', $acl)->value('note'),
            DB::table('aco_map')->where('acl_id', $acl)->pluck('value')->all(), DB::table('axo_map')->where('acl_id', $acl)->pluck('value')->all(),
            DB::table('aro_groups_map')->where('acl_id', $acl)->pluck('group_id')->map(fn ($i) => (int) $i)->all()]);
        $a = $this->lastAudit('acl');
        $this->assertSame(['updated', ['note' => 'Char pravidlo'], ['note' => 'Char upraveno', 'actions' => ['view_own'], 'axo_ids' => [$x3], 'group_ids' => [$g2]]],
            [$a->action, json_decode($a->old_values, true), json_decode($a->new_values, true)]);
        $this->assertSame(['all'], $this->flushes);
        $this->as()->put(route('acl.update', $acl), ['note' => 'Char bez skupin', 'actions' => ['view_own'], 'axo_ids' => [$x3]]);
        $this->assertFalse(DB::table('aro_groups_map')->where('acl_id', $acl)->exists(), 'bez group_ids = odebrat ze všech skupin');

        $this->flushes = [];
        $this->as()->delete(route('acl.destroy', $acl))->assertRedirect(route('aro-groups.index'))->assertSessionHas('success', 'ACL pravidlo bylo smazáno.');
        foreach (['aco_map', 'axo_map', 'aro_groups_map'] as $t) {
            $this->assertFalse(DB::table($t)->where('acl_id', $acl)->exists(), $t);
        }
        $this->assertFalse(DB::table('acl')->where('id', $acl)->exists());
        $a = $this->lastAudit('acl');
        $this->assertSame(['deleted', ['note' => 'Char bez skupin']], [$a->action, json_decode($a->old_values, true)]);
        $this->assertSame(['all'], $this->flushes);
    }

    // ── ACL ──────────────────────────────────────────────────────────────────

    public function test_prava_akci(): void
    {
        $g = $this->group('Char G');
        $acl = (int) DB::table('acl')->insertGetId(['note' => 'char']);
        $this->acl(['new_all/Aro_groups_Controller/aro_group', 'edit_all/Aro_groups_Controller/aro_group', 'delete_all/Aro_groups_Controller/aro_group']);
        $this->as()->get(route('aro-groups.create'))->assertForbidden();
        $this->as()->post(route('aro-groups.store'), ['name' => 'x', 'parent_id' => $this->rootId])->assertForbidden();
        $this->as()->get(route('aro-groups.edit', $g))->assertForbidden();
        $this->as()->put(route('aro-groups.update', $g), ['name' => 'x', 'parent_id' => $this->rootId])->assertForbidden();
        $this->as()->delete(route('aro-groups.destroy', $g))->assertForbidden();
        $this->as()->post(route('aro-groups.add-user', $g), ['user_id' => $this->adminId])->assertForbidden();
        $this->as()->delete(route('aro-groups.remove-user', [$g, $this->adminId]))->assertForbidden();
        $this->as()->post(route('aro-groups.add-acl', $g), ['acl_id' => $acl])->assertForbidden();
        $this->as()->delete(route('aro-groups.remove-acl', [$g, $acl]))->assertForbidden();
        $this->as()->post(route('aro-groups.mfa-required', $g), ['required' => '1'])->assertForbidden();
        $this->as()->get(route('acl.create'))->assertForbidden();
        $this->as()->post(route('acl.store'), [])->assertForbidden();
        $this->as()->get(route('acl.edit', $acl))->assertForbidden();
        $this->as()->put(route('acl.update', $acl), [])->assertForbidden();
        $this->as()->delete(route('acl.destroy', $acl))->assertForbidden();
        $this->assertTrue(DB::table('acl')->where('id', $acl)->exists());
        $this->assertTrue(DB::table('aro_groups')->where('id', $g)->exists());
    }
}
