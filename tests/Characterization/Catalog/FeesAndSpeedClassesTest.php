<?php

namespace Tests\Characterization\Catalog;

use App\Models\Fee;
use Illuminate\Support\Facades\DB;

/**
 * Charakterizace tarifů / poplatků (FeeController) a tříd rychlosti
 * (SpeedClassController): seznamy, členové s tarifem / rychlostí, CRUD,
 * archivace jen bez aktivního přiřazení, systémový tarif needitovatelný,
 * mazání jen nepoužitých, výchozí třída pro člena / žadatele, ACL.
 */
class FeesAndSpeedClassesTest extends CatalogTestCase
{
    private function member(): int
    {
        return (int) DB::table('members')->insertGetId(['name' => "Char {$this->tag}", 'type' => 2, 'registration' => 1, 'locked' => 0,
            'address_point_id' => (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id'),
            'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31']);
    }

    // ── tarify ───────────────────────────────────────────────────────────────

    public function test_tarify(): void
    {
        $this->as()->get(route('fees.create'))->assertOk()->assertViewHas('typeLabels', Fee::typeLabels());
        $this->as()->post(route('fees.store'), ['type_id' => Fee::TYPE_REGULAR, 'name' => "Tarif {$this->tag}", 'fee' => 349, 'from' => '2026-01-01', 'to' => '9999-12-31'])
            ->assertRedirect(route('fees.index'))->assertSessionHas('success', 'Tarif byl úspěšně přidán.');
        $fee = Fee::where('name', "Tarif {$this->tag}")->firstOrFail();
        $this->assertSame([0, 349.0], [(int) $fee->readonly, (float) $fee->fee]);
        $this->as()->post(route('fees.store'), ['type_id' => 999, 'fee' => -1, 'from' => '2026-02-01', 'to' => '2026-01-01'])->assertSessionHasErrors(['type_id', 'fee', 'to']);

        $m = $this->member();
        DB::table('members_fees')->insert(['fee_id' => $fee->id, 'member_id' => $m, 'activation_date' => '2020-01-01', 'deactivation_date' => '9999-12-31', 'priority' => 0, 'comment' => 'c']);
        $idx = $this->as()->get(route('fees.index', ['sort' => 'id', 'dir' => 'desc']))->assertOk();
        $row = collect($idx->viewData('fees')->items())->firstWhere('id', $fee->id);
        $this->assertSame([1, true], [$row->active_count, $row->has_active]);
        $this->assertSame([$m], $this->as()->get(route('fees.members', $fee->id))->assertOk()->viewData('members')->pluck('member_id')->map(fn ($i) => (int) $i)->all());

        $this->as()->from(route('fees.index'))->post(route('fees.toggle_archive', $fee->id))
            ->assertSessionHas('error', 'Tarif má aktivní přiřazení, nelze ho archivovat. Nejdřív ho odeberte členům.');
        $this->as()->from(route('fees.index'))->delete(route('fees.destroy', $fee->id))->assertSessionHas('error', 'Tarif nelze smazat, je přiřazen členům.');
        DB::table('members_fees')->where('fee_id', $fee->id)->delete();
        $this->as()->from(route('fees.index'))->post(route('fees.toggle_archive', $fee->id))
            ->assertSessionHas('success', 'Tarif byl archivován (skryt z nabídky). Historie zůstává zachována.');
        $this->assertNotContains($fee->id, collect($this->as()->get(route('fees.index'))->viewData('fees')->items())->pluck('id')->all(), 'archivovaný skrytý');
        $this->assertContains($fee->id, collect($this->as()->get(route('fees.index', ['archived' => 1, 'sort' => 'id', 'dir' => 'desc']))->viewData('fees')->items())->pluck('id')->all());
        $this->as()->from(route('fees.index'))->post(route('fees.toggle_archive', $fee->id))->assertSessionHas('success', 'Tarif byl obnoven do nabídky.');

        $this->as()->get(route('fees.edit', $fee->id))->assertOk();
        $this->as()->put(route('fees.update', $fee->id), ['type_id' => Fee::TYPE_REGULAR, 'name' => 'x', 'fee' => 399, 'from' => '2026-01-01', 'to' => '9999-12-31'])
            ->assertRedirect(route('fees.index'))->assertSessionHas('success', 'Tarif byl úspěšně upraven.');
        $this->assertSame(399.0, (float) $fee->fresh()->fee);
        $ro = (int) DB::table('fees')->insertGetId(['readonly' => 1, 'archived' => 0, 'fee' => 0, 'from' => '2000-01-01', 'to' => '9999-12-31', 'type_id' => Fee::TYPE_REGULAR, 'name' => 'sys']);
        $this->as()->get(route('fees.edit', $ro))->assertForbidden();
        $this->as()->put(route('fees.update', $ro), ['type_id' => Fee::TYPE_REGULAR, 'fee' => 1, 'from' => '2026-01-01', 'to' => '2026-12-31'])->assertForbidden();
        $this->as()->from(route('fees.index'))->delete(route('fees.destroy', $ro))->assertSessionHas('error', 'Tento tarif je systémový a nelze ho smazat.');
        $this->as()->delete(route('fees.destroy', $fee->id))->assertRedirect(route('fees.index'))->assertSessionHas('success', 'Tarif byl úspěšně smazán.');
        $this->assertNull(Fee::find($fee->id));
    }

    // ── třídy rychlosti ──────────────────────────────────────────────────────

    public function test_tridy_rychlosti(): void
    {
        $this->as()->post(route('speed_classes.store'), ['name' => "Rych {$this->tag}", 'qos_ceil' => '250M/50M', 'qos_rate' => '100M', 'price' => '349,50'])
            ->assertRedirect(route('speed_classes.index'))->assertSessionHas('success', 'Třída rychlosti byla přidána.');
        $sc = DB::table('speed_classes')->where('name', "Rych {$this->tag}")->first();
        $this->assertSame([250000000, 50000000, 100000000, 100000000, 349.5, 0, 0],
            [(int) $sc->d_ceil, (int) $sc->u_ceil, (int) $sc->d_rate, (int) $sc->u_rate, (float) $sc->price, (int) $sc->regular_member_default, (int) $sc->applicant_default]);
        $this->as()->post(route('speed_classes.store'), ['name' => "Rych {$this->tag}", 'qos_ceil' => '', 'qos_rate' => ''])
            ->assertSessionHasErrors(['name' => 'Třída rychlosti s tímto jménem již existuje.', 'qos_ceil', 'qos_rate']);

        $this->as()->get(route('speed_classes.index'))->assertOk();
        $this->as()->get(route('speed_classes.show', $sc->id))->assertRedirect(route('speed_classes.index'));
        $this->as()->put(route('speed_classes.update', $sc->id), ['name' => "Rych2 {$this->tag}", 'qos_ceil' => '1G', 'qos_rate' => '512K/256K', 'price' => 'abc'])
            ->assertRedirect(route('speed_classes.index'))->assertSessionHas('success', 'Třída rychlosti byla upravena.');
        $u = DB::table('speed_classes')->where('id', $sc->id)->first();
        $this->assertSame([1000000000, 512000, 256000, null], [(int) $u->d_ceil, (int) $u->d_rate, (int) $u->u_rate, $u->price], 'nečíselná cena = bez ceny');

        $this->as()->from(route('speed_classes.index'))->post(route('speed_classes.set_default', [$sc->id, 'member']))->assertSessionHas('success');
        $this->assertSame([$sc->id], DB::table('speed_classes')->where('regular_member_default', 1)->pluck('id')->map(fn ($i) => (int) $i)->all(), 'jediná výchozí');
        $this->as()->from(route('speed_classes.index'))->post(route('speed_classes.set_default', [$sc->id, 'member']));
        $this->assertSame(0, (int) DB::table('speed_classes')->where('id', $sc->id)->value('regular_member_default'), 'podruhé vypne');
        $this->as()->post(route('speed_classes.set_default', [$sc->id, 'nesmysl']))->assertNotFound();

        $m = $this->member();
        DB::table('members')->where('id', $m)->update(['speed_class_id' => $sc->id]);
        $this->assertSame([$m], $this->as()->get(route('speed_classes.members', $sc->id))->assertOk()->viewData('members')->pluck('id')->map(fn ($i) => (int) $i)->all());
        $this->as()->from(route('speed_classes.index'))->delete(route('speed_classes.destroy', $sc->id))->assertSessionHas('error', 'Třídu nelze smazat — je přiřazena 1 členům.');
        DB::table('members')->where('id', $m)->update(['speed_class_id' => null]);
        $this->as()->delete(route('speed_classes.destroy', $sc->id))->assertRedirect(route('speed_classes.index'))->assertSessionHas('success', 'Třída rychlosti byla smazána.');
    }

    public function test_prava(): void
    {
        $fee = (int) DB::table('fees')->insertGetId(['readonly' => 0, 'archived' => 0, 'fee' => 1, 'from' => '2000-01-01', 'to' => '9999-12-31', 'type_id' => Fee::TYPE_REGULAR, 'name' => 'c']);
        $sc = (int) DB::table('speed_classes')->value('id');
        $this->acl(['view_all/Fees_Controller/fees', 'new_all/Fees_Controller/fees', 'edit_all/Fees_Controller/fees', 'delete_all/Fees_Controller/fees',
            'view_all/Speed_classes_Controller/speed_classes', 'new_all/Speed_classes_Controller/speed_classes', 'edit_all/Speed_classes_Controller/speed_classes',
            'delete_all/Speed_classes_Controller/speed_classes']);
        foreach ([route('fees.index'), route('fees.members', $fee), route('fees.create'), route('fees.edit', $fee),
                  route('speed_classes.index'), route('speed_classes.members', $sc), route('speed_classes.create'), route('speed_classes.edit', $sc)] as $url) {
            $this->as()->get($url)->assertForbidden();
        }
        $this->as()->post(route('fees.toggle_archive', $fee))->assertForbidden();
        $this->as()->delete(route('fees.destroy', $fee))->assertForbidden();
        $this->as()->delete(route('speed_classes.destroy', $sc))->assertForbidden();
        $this->as()->post(route('speed_classes.set_default', [$sc, 'member']))->assertForbidden();
    }
}
