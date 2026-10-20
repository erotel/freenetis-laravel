<?php

namespace Tests\Characterization\Contracts;

use App\Models\Contract;
use App\Models\ContractAddon;
use App\Models\User;
use App\Services\AclService;
use App\Services\Contracts\PdfService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Charakterizace správy smluv (ContractController a akce domény Contracts)
 * před přesunem do domény Contracts: založení a jeho podmínky, snímek smluvní
 * strany, aktualizace údajů, místo připojení, zrušení / ukončení, odkazy,
 * starý dodatek, admin akce tří typů dodatků, náhled/stažení, seznam a odznak.
 * Číslo smlouvy a mazání návrhu: Feature/Contracts/ContractNumberTest. PDF podvržené.
 */
class ContractAdminTest extends DatabaseTestCase
{
    private int $adminId;
    private int $memberId;
    private int $userId;
    private int $speedId;
    private string $tag;
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = (int) DB::table('users')->where('type', 1)->value('id');
        $this->acl();
        $this->tag = substr(md5(uniqid('', true)), 0, 6);

        $town   = (int) DB::table('towns')->value('id');
        $street = (int) DB::table('streets')->insertGetId(['street' => 'Smluvni' . $this->tag]);
        $ap     = (int) DB::table('address_points')->insertGetId(['town_id' => $town, 'street_id' => $street, 'street_number' => '7', 'country_id' => 1]);
        $this->speedId = (int) DB::table('speed_classes')->insertGetId(['name' => 'Char Smlouva', 'd_ceil' => 1, 'd_rate' => 1, 'u_ceil' => 1, 'u_rate' => 1, 'price' => 444]);
        $this->memberId = (int) DB::table('members')->insertGetId([
            'name' => 'Char Smluvni', 'type' => 2, 'registration' => 1, 'locked' => 0, 'address_point_id' => $ap, 'speed_class_id' => $this->speedId,
            'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31', 'organization_identifier' => '', 'vat_organization_identifier' => '',
        ]);
        $this->userId = (int) DB::table('users')->insertGetId(['member_id' => $this->memberId, 'login' => 'charsml_' . uniqid(), 'password' => Hash::make('x'),
            'type' => User::MAIN_USER, 'application_password' => 'xxxxxxxx', 'settings' => '', 'name' => 'Char', 'surname' => 'Smluvni', 'comment' => '', 'birthday' => '1985-04-03']);
        foreach ([[20, 'charsml@example.invalid'], [21, '+420777999888']] as [$type, $value]) {
            $cid = (int) DB::table('contacts')->insertGetId(['type' => $type, 'value' => $value]);
            DB::table('users_contacts')->insert(['user_id' => $this->userId, 'contact_id' => $cid]);
        }
        $acc = (int) DB::table('accounts')->insertGetId(['member_id' => $this->memberId, 'account_attribute_id' => 221100, 'balance' => 0, 'name' => 'c']);
        DB::table('variable_symbols')->insert(['account_id' => $acc, 'variable_symbol' => '55' . $this->memberId]);

        $pdf = $this->createMock(PdfService::class);
        foreach (['renderContractPdf', 'renderTariffAddonPdf', 'renderServiceAddonPdf', 'renderConnectionPointAddonPdf'] as $m) {
            $pdf->method($m)->willReturnCallback(fn ($x, $preview = false) => $preview === true ? '%PDF-preview-' . $m : $this->file($m));
        }
        $this->app->instance(PdfService::class, $pdf);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $f) @unlink($f);
        parent::tearDown();
    }

    private function file(string $tag): string
    {
        $f = tempnam(sys_get_temp_dir(), 'charca');
        file_put_contents($f, '%PDF-' . $tag);
        return $this->files[] = $f;
    }

    private function acl(array $deny = []): void
    {
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturnUsing(fn ($u, $aco, $section, $value) => !in_array("{$aco}/{$section}/{$value}", $deny, true));
        $acl->shouldReceive('flushUserCache')->andReturnNull();
    }

    private function as(?int $uid = null)
    {
        return $this->actingAs(User::find($uid ?? $this->adminId))->from(route('contracts.show', $this->memberId));
    }

    private function contract(): ?Contract
    {
        return Contract::where('member_id', $this->memberId)->orderByDesc('id')->first();
    }

    private function events(int $cid, string $event)
    {
        return DB::connection('contracts')->table('contract_events')->where('contract_id', $cid)->where('event', $event)->get()->map(fn ($e) => json_decode($e->meta_json, true));
    }

    private function signedContract(): Contract
    {
        $id = (int) DB::connection('contracts')->table('contracts')->insertGetId(['member_id' => $this->memberId, 'contract_no' => 'SML-CHAR-CA-' . $this->tag, 'status' => 'signed', 'created_at' => now()]);
        DB::connection('contracts')->table('contract_parties')->insert(['contract_id' => $id, 'full_name' => 'Char Smluvni', 'email' => 'charsml@example.invalid', 'created_at' => now()]);
        return Contract::find($id);
    }

    // ── založení ─────────────────────────────────────────────────────────────

    public function test_zalozeni_snimek_strany_a_udalost(): void
    {
        DB::table('devices')->insert(['name' => 'char', 'type' => 8, 'user_id' => $this->userId,
            'address_point_id' => DB::table('address_points')->insertGetId(['town_id' => (int) DB::table('towns')->value('id'), 'street_id' => null, 'street_number' => '1', 'country_id' => 1])]);
        $this->as()->post(route('contracts.create', $this->memberId))->assertRedirect(route('contracts.show', $this->memberId))
            ->assertSessionHas('success', 'Smlouva ' . sprintf('SML-%s-%06d', date('Y'), $this->memberId) . ' byla vytvořena.');

        $c = $this->contract();
        $this->assertSame(['draft', '+420777999888'], [$c->status, $c->phone]);
        $p = DB::connection('contracts')->table('contract_parties')->where('contract_id', $c->id)->first();
        $town = DB::table('towns')->where('id', DB::table('address_points')->where('id', DB::table('members')->where('id', $this->memberId)->value('address_point_id'))->value('town_id'))->value('town');
        $this->assertSame(['Char Smluvni', 'Smluvni' . $this->tag . ' 7', $town, 'CZ', '1985-04-03', 'Char Smlouva', '55' . $this->memberId, 444.0, 'charsml@example.invalid', '+420777999888'],
            [$p->full_name, $p->street, $p->town, $p->country, $p->birthday, $p->speed_name, $p->variable_symbol, (float) $p->price, $p->email, $p->phone]);
        $this->assertSame('', (string) $p->service_full_address, 'zařízení bez ulice → místo připojení prázdné');
        $this->assertSame([['member_id' => $this->memberId, 'by' => 'admin', 'login' => User::find($this->adminId)->login]], $this->events($c->id, 'created')->all());

        $this->as()->post(route('contracts.create', $this->memberId))->assertSessionHas('error', 'Smlouva pro tohoto člena již existuje (#' . $c->contract_no . ').');
    }

    public function test_zalozeni_podminky(): void
    {
        DB::table('members')->where('id', $this->memberId)->update(['type' => 90]);
        $this->as()->post(route('contracts.create', $this->memberId))->assertRedirect(route('members.show', $this->memberId))
            ->assertSessionHas('error', 'Řádný člen (typ 90) nemá smlouvu — vytvoření není povoleno.');
        DB::table('members')->where('id', $this->memberId)->update(['type' => 2, 'speed_class_id' => null]);
        $this->as()->post(route('contracts.create', $this->memberId))->assertRedirect(route('members.edit', $this->memberId))
            ->assertSessionHas('error', 'Před vytvořením smlouvy nastavte třídu rychlosti (QoS) v editaci člena.');
        DB::table('members')->where('id', $this->memberId)->update(['speed_class_id' => $this->speedId]);
        DB::table('users')->where('id', $this->userId)->update(['birthday' => null]);
        $this->as()->post(route('contracts.create', $this->memberId))->assertRedirect(route('users.edit', $this->userId))
            ->assertSessionHas('error', 'Před vytvořením smlouvy vyplňte datum narození hlavního uživatele (nebo IČO u organizace).');
        DB::table('members')->where('id', $this->memberId)->update(['organization_identifier' => '12345678']);
        $this->as()->post(route('contracts.create', $this->memberId))->assertSessionHas('success');
        $this->assertNull($this->contract()->parties()->first()->birthday);

        $this->acl(['edit_all/Members_Controller/members']);
        $this->as()->post(route('contracts.create', $this->memberId))->assertForbidden();
    }

    // ── návrh: aktualizace, místo připojení, zrušení ─────────────────────────

    public function test_navrh_aktualizace_mista_a_zruseni(): void
    {
        $this->as()->post(route('contracts.create', $this->memberId));
        $c = $this->contract();
        DB::table('members')->where('id', $this->memberId)->update(['name' => 'Char Prejmenovany']);
        DB::connection('contracts')->table('contracts')->where('id', $c->id)->update(['phone' => 'stary']);

        $this->as()->post(route('contracts.refresh', $this->memberId))->assertSessionHas('success', 'Údaje ve smlouvě byly aktualizovány podle aktuálních údajů člena.');
        $this->assertSame('Char Prejmenovany', DB::connection('contracts')->table('contract_parties')->where('contract_id', $c->id)->value('full_name'));
        $this->assertSame('+420777999888', DB::connection('contracts')->table('contracts')->where('id', $c->id)->value('phone'), 'telefon i na smlouvě');
        $diff = $this->events($c->id, 'party_refreshed')->first()['diff'];
        $this->assertSame(['from' => 'Char Smluvni', 'to' => 'Char Prejmenovany'], $diff['full_name']);

        $this->as()->post(route('contracts.service-address', $this->memberId), ['service_full_address' => '  Nova 1, 79601 Mesto  '])
            ->assertSessionHas('success', 'Místo připojení bylo upraveno.');
        $p = DB::connection('contracts')->table('contract_parties')->where('contract_id', $c->id)->first();
        $this->assertSame(['Nova 1, 79601 Mesto', null, null, null], [$p->service_full_address, $p->service_street, $p->service_town, $p->service_zip]);
        $this->assertSame('Nova 1, 79601 Mesto', $this->events($c->id, 'service_address_edited')->first()['to']);

        $this->as()->post(route('contracts.terminate', $this->memberId))->assertSessionHas('error', 'Ukončit lze pouze podepsanou smlouvu.');
        $this->as()->post(route('contracts.cancel', $this->memberId), ['reason' => 'test'])->assertSessionHas('success', 'Smlouva byla zrušena. Můžete vytvořit novou.');
        $this->assertNull($this->contract());
        $this->as()->post(route('contracts.cancel', $this->memberId))->assertRedirect(route('members.show', $this->memberId))->assertSessionHas('error', 'Smlouva nenalezena.');
    }

    public function test_podepsana_nelze_upravit_ani_zrusit_ukonceni(): void
    {
        $c = $this->signedContract();
        $this->as()->post(route('contracts.refresh', $this->memberId))->assertSessionHas('error', 'Aktualizace údajů je možná jen u smlouvy ve stavu Návrh. V ostatních stavech smlouvu nejdřív zrušte a vytvořte novou.');
        $this->as()->post(route('contracts.service-address', $this->memberId))->assertSessionHas('error', 'Místo připojení lze upravit jen u smlouvy ve stavu Návrh.');
        $this->as()->post(route('contracts.cancel', $this->memberId))->assertSessionHas('error', 'Zrušit lze pouze nepodepsanou smlouvu.');
        $this->as()->post(route('contracts.send-link', $this->memberId))->assertSessionHas('error', 'Odkaz lze odeslat pouze pro smlouvy ve stavu Návrh nebo Čeká na podpis.');

        $this->as()->post(route('contracts.terminate', $this->memberId), ['reason' => 'odchod', 'termination_date' => '2026-12-31'])
            ->assertSessionHas('success', 'Smlouva byla označena jako ukončená.');
        $this->assertSame('terminated', Contract::find($c->id)->status);
        $this->assertSame(['by' => User::find($this->adminId)->login, 'reason' => 'odchod', 'date' => '2026-12-31'], $this->events($c->id, 'terminated')->first());
    }

    public function test_odkaz_k_podpisu(): void
    {
        $this->as()->post(route('contracts.create', $this->memberId));
        $res = $this->as()->post(route('contracts.send-link', $this->memberId))
            ->assertSessionHas('success', 'Podpisový odkaz byl vygenerován a odeslán na email zákazníka.');
        $this->assertStringStartsWith(route('sign.show') . '?t=', session('sign_link'));
    }

    // ── starý dodatek (contracts.addon) ──────────────────────────────────────

    public function test_stary_dodatek(): void
    {
        $this->as()->post(route('contracts.create', $this->memberId));
        $this->as()->post(route('contracts.addon.create', $this->memberId))->assertSessionHas('error', 'Dodatek lze vytvořit pouze k podepsané smlouvě.');
        $this->as()->post(route('contracts.addon.send-link', $this->memberId))->assertSessionHas('error', 'Dodatek nenalezen.');

        $c = $this->signedContract();
        $this->as()->post(route('contracts.addon.create', $this->memberId))->assertSessionHas('success', 'Dodatek byl vytvořen.');
        $this->assertSame(1, (int) Contract::find($c->id)->addon);
        $this->assertSame(1, $this->events($c->id, 'addon_created')->count());
        $this->as()->post(route('contracts.addon.create', $this->memberId))->assertSessionHas('error', 'Dodatek k této smlouvě již existuje.');

        $this->as()->post(route('contracts.addon.send-link', $this->memberId))->assertSessionHas('success');
        $this->assertStringStartsWith(route('sign.addon.show') . '?t=', session('addon_link'));

        $file = $this->file('addon');
        DB::connection('contracts')->table('contracts')->where('id', $c->id)->update(['addon_pdf_path' => $file]);
        $res = $this->as()->get(route('contracts.addon.download', $c->id))->assertOk();
        $this->assertStringContainsString('dodatek-' . $c->contract_no . '.pdf', $res->headers->get('Content-Disposition'));

        DB::connection('contracts')->table('contract_addon_otps')->insert(['contract_id' => $c->id, 'otp_hash' => 'x', 'expires_at' => now(), 'created_at' => now()]);
        $this->as()->delete(route('contracts.addon.delete', $c->id))->assertSessionHas('success', 'Dodatek byl smazán.');
        $row = DB::connection('contracts')->table('contracts')->where('id', $c->id)->first();
        $this->assertSame([0, null, null], [(int) $row->addon, $row->addon_pdf_path, $row->addon_signed_at]);
        $this->assertFileDoesNotExist($file);
        $this->assertFalse(DB::connection('contracts')->table('contract_addon_otps')->where('contract_id', $c->id)->exists());
        $this->assertSame(1, $this->events($c->id, 'addon_deleted')->count());

        DB::connection('contracts')->table('contracts')->where('id', $c->id)->update(['addon' => 1, 'addon_signed' => 1]);
        $this->as()->delete(route('contracts.addon.delete', $c->id))->assertSessionHas('error', 'Podepsaný dodatek nelze smazat.');
        $this->as()->post(route('contracts.addon.send-link', $this->memberId))->assertSessionHas('error', 'Dodatek je již podepsán.');
        $this->assertSame('signed', \App\Domains\Contracts\Actions\CreateLegacyAddonAction::status(Contract::find($c->id)));
    }

    // ── dodatky: admin akce ──────────────────────────────────────────────────

    public function test_admin_akce_dodatku(): void
    {
        $this->as()->post(route('contracts.tariff_addon.create', $this->memberId), ['speed_class_id' => $this->speedId])
            ->assertSessionHas('error', 'Dodatek – změnu tarifu lze vytvořit jen k podepsané smlouvě.');
        $c = $this->signedContract();

        $this->as()->post(route('contracts.tariff_addon.create', $this->memberId), ['speed_class_id' => $this->speedId])
            ->assertSessionHas('success', 'Dodatek – změna tarifu byl vytvořen (návrh). Zkontrolujte náhled PDF.');
        $fee = (int) DB::table('fees')->insertGetId(['readonly' => 0, 'fee' => 50, 'from' => '2000-01-01', 'to' => '9999-12-31',
            'type_id' => (int) DB::table('enum_types')->whereRaw("LOWER(value) = 'additional service'")->value('id'), 'name' => 'Char S', 'special_type_id' => null]);
        $this->as()->post(route('contracts.service_addon.create', $this->memberId), ['fee_id' => 999999999, 'action' => 'add'])
            ->assertSessionHas('error', 'Dodatek se nepodařilo vytvořit (služba nenalezena nebo není typu „Dodatečné služby").');
        $this->as()->post(route('contracts.service_addon.create', $this->memberId), ['fee_id' => $fee, 'action' => 'remove'])
            ->assertSessionHas('success', 'Dodatek – dodatečná služba byl vytvořen (návrh). Zkontrolujte náhled PDF.');
        $subnet = (int) DB::table('subnets')->insertGetId(['name' => 'char ca', 'network_address' => '10.250.20.0', 'netmask' => '255.255.255.0']);
        $place  = (int) DB::table('allowed_subnets')->insertGetId(['member_id' => $this->memberId, 'subnet_id' => $subnet, 'enabled' => 1, 'charged' => 1, 'speed_class_id' => $this->speedId]);
        $this->as()->post(route('contracts.connection_point.create', $this->memberId), ['allowed_subnet_id' => $place, 'action' => 'add'])
            ->assertSessionHasErrors(['address' => 'Adresa připojení musí být vyplněná (zařízení v podsíti nemá adresu — doplňte ji ručně nebo u zařízení).']);
        $this->as()->post(route('contracts.connection_point.create', $this->memberId), ['allowed_subnet_id' => $place, 'action' => 'add', 'address' => 'A 1'])
            ->assertSessionHas('success', 'Dodatek – přípojné místo byl vytvořen (návrh). Zkontrolujte náhled PDF.');

        $t = ContractAddon::where('contract_id', $c->id)->where('type', 'tariff_change')->first();
        $s = ContractAddon::where('contract_id', $c->id)->where('type', 'additional_service')->first();
        $p = ContractAddon::where('contract_id', $c->id)->where('type', 'connection_point')->first();

        foreach ([['tariff_addon', $t, 'dodatek-tarif'], ['service_addon', $s, 'dodatek-sluzba'], ['connection_point', $p, 'dodatek-misto']] as [$r, $a, $name]) {
            $res = $this->as()->get(route("contracts.{$r}.preview", $a->id))->assertOk();
            $this->assertSame('inline; filename="' . $name . '-preview.pdf"', $res->headers->get('Content-Disposition'));
            $this->as()->get(route("contracts.{$r}.download", $a->id))->assertRedirect()->assertSessionHas('error', 'PDF dodatku není dostupné.');
            $a->update(['pdf_path' => $this->file($r)]);
            $res = $this->as()->get(route("contracts.{$r}.download", $a->id))->assertOk();
            $this->assertStringContainsString($name . '-' . $a->id . '.pdf', $res->headers->get('Content-Disposition'));
        }

        // odkazy k podpisu
        $this->as()->post(route('contracts.tariff_addon.send-link', $t->id))->assertSessionHas('success', 'Odkaz pro podpis dodatku byl vygenerován a odeslán na email zákazníka.');
        $this->assertNotNull(session('tariff_addon_link'));
        $this->as()->post(route('contracts.service_addon.send-link', $s->id))
            ->assertSessionHas('error', 'Odkaz k podpisu je jen pro přidání služby. Zrušení vydává poskytovatel bez podpisu zákazníka.');
        $this->as()->post(route('contracts.connection_point.send-link', $p->id))->assertSessionHas('success');
        $this->assertNotNull(session('connection_point_addon_link'));

        // vydání zrušení
        $this->as()->post(route('contracts.connection_point.issue-removal', $p->id))->assertSessionHas('error', 'Vydat bez podpisu lze jen dodatek o zrušení přípojného místa.');
        $this->as()->post(route('contracts.service_addon.issue-removal', $s->id))
            ->assertSessionHas('success', 'Dodatek o zrušení služby byl vydán a odeslán zákazníkovi. Nezapomeň službu deaktivovat v poplatcích člena.');
        $this->as()->post(route('contracts.service_addon.issue-removal', $s->id))->assertSessionHas('error', 'Dodatek je již vydán.');

        // mazání
        $this->as()->delete(route('contracts.tariff_addon.delete', $t->id))->assertSessionHas('success', 'Dodatek byl smazán.');
        $this->as()->delete(route('contracts.service_addon.delete', $s->id))->assertSessionHas('error', 'Podepsaný dodatek nelze smazat.');
        $this->as()->delete(route('contracts.connection_point.delete', 999999999))->assertNotFound();

        // dodatek jen přes routu svého typu (dřív šlo např. smazat přípojné místo přes URL tarifu)
        $this->as()->get(route('contracts.tariff_addon.preview', $p->id))->assertNotFound();
        $this->as()->delete(route('contracts.tariff_addon.delete', $p->id))->assertNotFound();
        $this->as()->post(route('contracts.tariff_addon.send-link', $p->id))->assertNotFound();
        $this->as()->post(route('contracts.service_addon.issue-removal', $p->id))->assertNotFound();
        $this->assertTrue(ContractAddon::whereKey($p->id)->exists());

        // zákazník bez práv: náhled / stažení jen vlastních dodatků, žádné admin akce
        $this->acl(['view_all/Members_Controller/members', 'edit_all/Members_Controller/members']);
        $this->as($this->userId)->get(route('contracts.connection_point.preview', $p->id))->assertOk();
        $this->as($this->userId)->get(route('contracts.connection_point.download', $p->id))->assertOk();
        $this->as($this->userId)->post(route('contracts.connection_point.send-link', $p->id))->assertForbidden();
        $this->as($this->userId)->delete(route('contracts.connection_point.delete', $p->id))->assertForbidden();
        $other = (int) DB::table('users')->where('member_id', '<>', $this->memberId)->where('id', '<>', $this->adminId)->value('id');
        $this->as($other)->get(route('contracts.connection_point.preview', $p->id))->assertForbidden();
        $this->as($other)->get(route('contracts.service_addon.download', $s->id))->assertForbidden();

        // tarifní dodatek vyžaduje i qos_ceil
        $this->acl(['edit_all/Members_Controller/qos_ceil']);
        $this->as()->post(route('contracts.tariff_addon.create', $this->memberId), ['speed_class_id' => $this->speedId])->assertForbidden();
    }

    // ── náhled, stažení, karta, seznam ───────────────────────────────────────

    public function test_nahled_stazeni_a_vlastni_profil(): void
    {
        $this->as()->post(route('contracts.create', $this->memberId));
        $c = $this->contract();
        $res = $this->as()->get(route('contracts.preview', $c->id))->assertOk();
        $this->assertSame('inline; filename="smlouva-' . $c->contract_no . '-nahled.pdf"', $res->headers->get('Content-Disposition'));
        $this->as()->get(route('contracts.download', $c->id))->assertRedirect()->assertSessionHas('error', 'PDF smlouvy není dostupné.');

        DB::connection('contracts')->table('contracts')->where('id', $c->id)->update(['status' => 'signed', 'pdf_path' => $this->file('signed')]);
        $res = $this->as()->get(route('contracts.preview', $c->id))->assertOk();
        $this->assertSame('inline; filename="smlouva-' . $c->contract_no . '.pdf"', $res->headers->get('Content-Disposition'));
        $res = $this->as()->get(route('contracts.download', $c->id))->assertOk();
        $this->assertStringContainsString('smlouva-' . $c->contract_no . '.pdf', $res->headers->get('Content-Disposition'));

        // zákazník bez práv vidí jen vlastní
        $this->acl(['view_all/Members_Controller/members', 'edit_all/Members_Controller/members']);
        $this->as($this->userId)->get(route('contracts.download', $c->id))->assertOk();
        $this->as($this->userId)->get(route('contracts.show', $this->memberId))->assertOk()->assertViewHas('isAdmin', false)->assertViewHas('canEdit', false);
        $this->as($this->userId)->get(route('contracts.show', 1))->assertForbidden();
        $this->as($this->userId)->get(route('contracts.index'))->assertForbidden();
    }

    public function test_karta_smlouvy_data(): void
    {
        $c = $this->signedContract();
        $this->as()->get(route('contracts.show', $this->memberId))->assertOk()
            ->assertViewHas('contract', fn ($x) => (int) $x->id === (int) $c->id)
            ->assertViewHas('isAdmin', true)->assertViewHas('canEditQos', true)
            ->assertViewHas('availableServices', fn ($s) => $s->every(fn ($f) => isset($f->id, $f->name)))
            ->assertViewHas('tariffAddons', fn ($a) => $a->isEmpty())
            ->assertViewHas('speedClasses', fn ($s) => $s->contains('id', $this->speedId));
    }

    public function test_seznam_a_odznak_bez_byvalych(): void
    {
        $this->as()->post(route('contracts.create', $this->memberId));
        $c = $this->contract();
        $badge = app(\App\Domains\Contracts\Queries\ContractListQuery::class)->countUnsigned();

        $ids = fn (array $q) => collect($this->as()->get(route('contracts.index', $q))->assertOk()->viewData('contracts')->items())->pluck('id')->all();
        $this->assertSame([$c->id], $ids(['q' => $c->contract_no]));
        $this->assertSame([$c->id], $ids(['q' => 'Char Smluvni', 'status' => 'draft']));
        $this->assertSame([], $ids(['q' => $c->contract_no, 'status' => 'signed']));
        $this->assertContains($c->id, $ids(['q' => (string) $this->memberId]));

        DB::table('members')->where('id', $this->memberId)->update(['type' => 16]);
        $this->assertSame([], $ids(['q' => $c->contract_no]), 'smlouva bývalého skrytá');
        $this->assertSame($badge - 1, app(\App\Domains\Contracts\Queries\ContractListQuery::class)->countUnsigned(), 'odznak bez bývalých');
        $c->update(['status' => 'terminated']);
        $this->assertSame([$c->id], $ids(['q' => $c->contract_no]), 'ukončená zůstává viditelná');
        $this->as()->get(route('contracts.index'))->assertViewHas('totalCounts', fn ($t) => is_array($t));
    }
}
