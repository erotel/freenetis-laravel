<?php

namespace Tests\Characterization\Members;

use Illuminate\Support\Facades\DB;
use Tests\Characterization\Catalog\CatalogTestCase;

/**
 * Charakterizace bílých listin členů (MemberWhitelistController) před
 * přesunem do domény Members: seznam (všech / člena), založení (trvalá =
 * do 9999-12-31), úprava, smazání, ACL.
 */
class MemberWhitelistsTest extends CatalogTestCase
{
    private function member(): int
    {
        return (int) DB::table('members')->insertGetId(['name' => "Char {$this->tag}", 'type' => 2, 'registration' => 1, 'locked' => 0,
            'address_point_id' => (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id'),
            'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31']);
    }

    private function rows(int $m): array
    {
        return DB::table('members_whitelists')->where('member_id', $m)->orderBy('id')->get(['permanent', 'since', 'until', 'comment'])
            ->map(fn ($r) => [(int) $r->permanent, $r->since, $r->until, $r->comment])->all();
    }

    public function test_seznam(): void
    {
        $m = $this->member();
        $a = (int) DB::table('members_whitelists')->insertGetId(['member_id' => $m, 'permanent' => 0, 'since' => '2026-01-01', 'until' => '2026-02-01', 'comment' => 'a']);
        $b = (int) DB::table('members_whitelists')->insertGetId(['member_id' => $m, 'permanent' => 1, 'since' => '2026-01-01', 'until' => '9999-12-31', 'comment' => 'b']);

        $all = $this->as()->get(route('member_whitelists.index'))->assertOk()->assertViewIs('member_whitelists.index');
        $this->assertSame([$b, $a], array_slice(collect($all->viewData('whitelists')->items())->pluck('id')->map(fn ($i) => (int) $i)->all(), 0, 2), 'nejnovější první');
        $this->assertNull($all->viewData('memberName'));

        $one = $this->as()->get(route('member_whitelists.index', ['member_id' => $m]));
        $items = collect($one->viewData('whitelists')->items());
        $this->assertSame([[$b, "Char {$this->tag}"], [$a, "Char {$this->tag}"]], $items->map(fn ($r) => [(int) $r->id, $r->member_name])->all());
        $this->assertSame(["Char {$this->tag}", $m], [$one->viewData('memberName'), $one->viewData('memberId')]);
        $this->assertTrue($one->viewData('canAdd') && $one->viewData('canEdit') && $one->viewData('canDelete'));
    }

    public function test_zalozeni_uprava_smazani(): void
    {
        $m = $this->member();
        $this->as()->get(route('member_whitelists.create', $m))->assertOk()->assertViewHas('action', 'create');
        $this->as()->get(route('member_whitelists.create', 999999999))->assertNotFound();

        $this->as()->post(route('member_whitelists.store', $m), ['since' => '2026-01-01', 'until' => '2026-03-01', 'comment' => 'dočasně'])
            ->assertRedirect(route('members.show', $m))->assertSessionHas('success', 'Whitelist záznam byl přidán.');
        $this->as()->post(route('member_whitelists.store', $m), ['permanent' => '1', 'since' => '2026-01-01', 'until' => '2026-03-01'])->assertSessionHasNoErrors();
        $this->assertSame([[0, '2026-01-01', '2026-03-01', 'dočasně'], [1, '2026-01-01', '9999-12-31', null]], $this->rows($m));
        $this->as()->post(route('member_whitelists.store', $m), ['since' => '2026-03-01', 'until' => '2026-01-01'])->assertSessionHasErrors(['until']);
        $this->as()->post(route('member_whitelists.store', 999999999), ['since' => '2026-01-01', 'until' => '2026-03-01'])->assertNotFound();

        $id = (int) DB::table('members_whitelists')->where('member_id', $m)->orderBy('id')->value('id');
        $edit = $this->as()->get(route('member_whitelists.edit', $id))->assertOk()->assertViewHas('action', 'edit');
        $this->assertSame($m, (int) $edit->viewData('member')->id);
        $this->as()->put(route('member_whitelists.update', $id), ['permanent' => '1', 'since' => '2026-02-01', 'until' => '2026-03-01', 'comment' => 'trvale'])
            ->assertRedirect(route('members.show', $m))->assertSessionHas('success', 'Whitelist záznam byl upraven.');
        $this->assertSame([1, '2026-02-01', '9999-12-31', 'trvale'], $this->rows($m)[0]);
        $this->as()->put(route('member_whitelists.update', 999999999), ['since' => '2026-01-01', 'until' => '2026-03-01'])->assertNotFound();

        $this->as()->delete(route('member_whitelists.destroy', $id))->assertRedirect(route('members.show', $m))->assertSessionHas('success', 'Whitelist záznam byl smazán.');
        $this->assertCount(1, $this->rows($m));
        $this->as()->delete(route('member_whitelists.destroy', $id))->assertNotFound();
    }

    public function test_prava(): void
    {
        $m = $this->member();
        $id = (int) DB::table('members_whitelists')->insertGetId(['member_id' => $m, 'permanent' => 0, 'since' => '2026-01-01', 'until' => '2026-02-01']);
        $this->acl(['view_all/Members_whitelists_Controller/whitelist', 'new_all/Members_whitelists_Controller/whitelist',
            'edit_all/Members_whitelists_Controller/whitelist', 'delete_all/Members_whitelists_Controller/whitelist']);
        foreach ([route('member_whitelists.index'), route('member_whitelists.create', $m), route('member_whitelists.edit', $id)] as $url) {
            $this->as()->get($url)->assertForbidden();
        }
        $this->as()->post(route('member_whitelists.store', $m), [])->assertForbidden();
        $this->as()->put(route('member_whitelists.update', $id), [])->assertForbidden();
        $this->as()->delete(route('member_whitelists.destroy', $id))->assertForbidden();
        $this->assertTrue(DB::table('members_whitelists')->where('id', $id)->exists());
    }
}
