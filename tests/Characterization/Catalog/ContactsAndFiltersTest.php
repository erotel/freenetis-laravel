<?php

namespace Tests\Characterization\Catalog;

use App\Models\Contact;
use Illuminate\Support\Facades\DB;

/**
 * Charakterizace kontaktů uživatele (ContactController) a uložených filtrů
 * (SavedFilterController): zobrazení vlastních / cizích, přidání (formát
 * e-mailu / telefonu, duplicita, sdílený řádek), úprava sdíleného kontaktu
 * („fork"), smazání (poslední e-mail / telefon chráněn), filtry jen vlastní.
 */
class ContactsAndFiltersTest extends CatalogTestCase
{
    private function user(): int
    {
        $m = (int) DB::table('members')->insertGetId(['name' => "Char {$this->tag}", 'type' => 2, 'registration' => 1, 'locked' => 0,
            'address_point_id' => (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id'),
            'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31']);
        return (int) DB::table('users')->insertGetId(['member_id' => $m, 'login' => 'charct_' . uniqid(), 'password' => 'x', 'type' => 1,
            'application_password' => 'x', 'settings' => '', 'name' => 'C', 'surname' => 'K', 'comment' => '']);
    }

    private function contacts(int $userId): array
    {
        return DB::table('contacts as c')->join('users_contacts as uc', 'uc.contact_id', '=', 'c.id')->where('uc.user_id', $userId)
            ->orderBy('c.type')->orderBy('c.value')->get(['c.type', 'c.value'])->map(fn ($r) => [(int) $r->type, $r->value])->all();
    }

    public function test_kontakty(): void
    {
        $u = $this->user();
        $this->as()->get(route('contacts.create', $u))->assertOk()->assertViewHas('contactTypes');
        $this->as()->post(route('contacts.store', $u), ['type' => Contact::TYPE_EMAIL, 'value' => "{$this->tag}@example.invalid"])
            ->assertRedirect(route('users.show', $u))->assertSessionHas('success', 'Kontakt byl úspěšně přidán.');
        $this->as()->post(route('contacts.store', $u), ['type' => Contact::TYPE_PHONE, 'value' => '+420 777 000 111'])->assertSessionHasNoErrors();
        $this->as()->from(route('contacts.create', $u))->post(route('contacts.store', $u), ['type' => Contact::TYPE_EMAIL, 'value' => "{$this->tag}@example.invalid"])
            ->assertSessionHasErrors(['value' => 'Tento kontakt je již přiřazen tomuto uživateli.']);
        $this->as()->post(route('contacts.store', $u), ['type' => Contact::TYPE_EMAIL, 'value' => 'neni-email'])->assertSessionHasErrors('value');
        $this->as()->post(route('contacts.store', $u), ['type' => Contact::TYPE_PHONE, 'value' => 'abc'])->assertSessionHasErrors('value');
        $this->assertSame([[Contact::TYPE_EMAIL, "{$this->tag}@example.invalid"], [Contact::TYPE_PHONE, '+420 777 000 111']], $this->contacts($u));

        // sdílený řádek (stejný e-mail u dvou uživatelů) — úprava se „forkne"
        $v = $this->user();
        $this->as()->post(route('contacts.store', $v), ['type' => Contact::TYPE_EMAIL, 'value' => "{$this->tag}@example.invalid"]);
        $shared = (int) DB::table('contacts')->where('value', "{$this->tag}@example.invalid")->value('id');
        $this->assertSame(2, DB::table('users_contacts')->where('contact_id', $shared)->count(), 'stejný řádek pro oba');
        $this->as()->get(route('contacts.edit', [$v, $shared]))->assertOk();
        $this->as()->put(route('contacts.update', [$v, $shared]), ['value' => "novy{$this->tag}@example.invalid"])
            ->assertRedirect(route('users.show', $v))->assertSessionHas('success', 'Kontakt byl úspěšně upraven.');
        $this->assertSame([[Contact::TYPE_EMAIL, "novy{$this->tag}@example.invalid"]], $this->contacts($v));
        $this->assertSame([Contact::TYPE_EMAIL, "{$this->tag}@example.invalid"], $this->contacts($u)[0], 'druhému zůstal');

        $phone = (int) DB::table('contacts')->where('value', '+420 777 000 111')->value('id');
        $this->as()->put(route('contacts.update', [$u, $phone]), ['value' => '+420 777 000 222']);
        $this->assertSame('+420 777 000 222', DB::table('contacts')->where('id', $phone)->value('value'), 'nesdílený se mění na místě');

        $this->acl(['delete_all/Users_Controller/additional_contacts_admin_delete']);
        $this->as()->delete(route('contacts.destroy', [$u, $phone]))->assertRedirect(route('users.show', $u))
            ->assertSessionHas('error', 'Nelze smazat poslední kontakt tohoto typu.');
        $this->acl();
        $this->as()->delete(route('contacts.destroy', [$u, $phone]))->assertSessionHas('success', 'Kontakt byl úspěšně smazán.');
        $this->assertFalse(DB::table('contacts')->where('id', $phone)->exists(), 'nepoužitý řádek pryč');
        $this->as()->delete(route('contacts.destroy', [$u, $phone]))->assertNotFound();

        $show = $this->as()->get(route('contacts.show_by_user', $u))->assertOk();
        $this->assertSame(1, $show->viewData('contacts')->count());
        $this->as($u)->get(route('contacts.show_by_user', $v))->assertForbidden();
        $this->as()->get(route('contacts.show_by_user', 999999999))->assertNotFound();
    }

    public function test_ulozene_filtry(): void
    {
        $other = $this->user();
        $this->as()->postJson(route('saved-filters.store'), ['name' => 'Moje', 'page' => 'members', 'filters' => ['type' => 2]])->assertOk()->assertJsonStructure(['id', 'name']);
        $id = (int) DB::table('saved_filters')->where('user_id', $this->adminId)->where('name', 'Moje')->value('id');
        $this->as()->postJson(route('saved-filters.store'), ['name' => 'Moje', 'page' => 'members', 'filters' => ['type' => 90]])->assertExactJson(['id' => $id, 'name' => 'Moje']);
        $this->assertSame(['type' => 90], json_decode(DB::table('saved_filters')->where('id', $id)->value('filters'), true), 'stejné jméno přepíše');
        $this->assertContains(['id' => $id, 'name' => 'Moje', 'filters' => ['type' => 90]], $this->as()->getJson(route('saved-filters.index', ['page' => 'members']))->json());
        $this->assertSame([], $this->as($other)->getJson(route('saved-filters.index', ['page' => 'members']))->json(), 'cizí nevidí');
        $this->as($other)->deleteJson(route('saved-filters.destroy', $id))->assertNotFound();
        $this->as()->deleteJson(route('saved-filters.destroy', $id))->assertExactJson(['ok' => true]);
        $this->as()->postJson(route('saved-filters.store'), [])->assertStatus(422);
    }
}
