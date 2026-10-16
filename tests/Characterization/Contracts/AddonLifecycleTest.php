<?php

namespace Tests\Characterization\Contracts;

use App\Models\ContractAddon;
use App\Models\Setting;
use App\Models\User;
use App\Services\Contracts\PdfService;
use App\Services\ContractService;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Charakterizace životního cyklu dodatků (ContractService) před sjednocením
 * tří kopií (tarif / služba / přípojné místo) v doméně Contracts. Aplikaci
 * na člena po podpisu pokrývají Feature/Contracts/*ApplyOnSignTest; tady
 * číslování, události, mazání, cesty k PDF, seznamy, kontroly zakládání,
 * podpis (otisk PDF) a vydání zrušení s e-mailem. PDF podvrženo (bez zápisu).
 */
class AddonLifecycleTest extends DatabaseTestCase
{
    private ContractService $svc;
    private int $memberId;
    private int $contractId;
    private string $login;
    private string $pdfFile;
    private PdfService $pdf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(ContractService::class);
        $apId = (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id');
        $this->memberId = (int) DB::table('members')->insertGetId([
            'name' => 'Char Addon', 'type' => 2, 'registration' => 1, 'locked' => 0, 'address_point_id' => $apId,
            'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31',
        ]);
        $this->contractId = (int) DB::connection('contracts')->table('contracts')->insertGetId([
            'member_id' => $this->memberId, 'contract_no' => 'SML-CHAR-AD-' . uniqid(), 'status' => 'signed', 'created_at' => now(),
        ]);
        DB::connection('contracts')->table('contract_parties')->insert([
            'contract_id' => $this->contractId, 'full_name' => 'Char Addon', 'street' => 'X 1', 'town' => 'Y', 'country' => 'ČR',
            'variable_symbol' => '1', 'email' => 'charaddon@example.invalid', 'created_at' => now(),
        ]);
        $admin = User::find((int) DB::table('users')->where('type', 1)->value('id'));
        $this->login = $admin->login;
        $this->actingAs($admin);
        Setting::set('email_subject_prefix', 'CHAR');

        $this->pdfFile = tempnam(sys_get_temp_dir(), 'charaddon');
        file_put_contents($this->pdfFile, '%PDF-char');
        $this->pdf = $this->createMock(PdfService::class);
        foreach (['renderTariffAddonPdf', 'renderServiceAddonPdf', 'renderConnectionPointAddonPdf'] as $m) {
            $this->pdf->method($m)->willReturn($this->pdfFile);
        }
    }

    protected function tearDown(): void
    {
        @unlink($this->pdfFile);
        parent::tearDown();
    }

    private function events(string $event)
    {
        return DB::connection('contracts')->table('contract_events')->where('contract_id', $this->contractId)->where('event', $event)
            ->orderBy('id')->get()->map(fn ($e) => json_decode($e->meta_json, true));
    }

    private function serviceFeeId(): int
    {
        $type = (int) DB::table('enum_types')->whereRaw("LOWER(value) = 'additional service'")->value('id');
        return (int) DB::table('fees')->insertGetId(['readonly' => 0, 'fee' => 50, 'from' => '2000-01-01', 'to' => '9999-12-31',
            'type_id' => $type, 'name' => 'Char služba', 'special_type_id' => null]);
    }

    /** @return array{id:int, subnet:int} placené místo člena s rychlostí */
    private function place(?int $speed = null): array
    {
        $subnet = (int) DB::table('subnets')->insertGetId(['name' => 'char addon', 'network_address' => '10.250.18.0', 'netmask' => '255.255.255.0']);
        $speed ??= (int) DB::table('speed_classes')->insertGetId(['name' => 'Char rychlost', 'd_ceil' => 1, 'd_rate' => 1, 'u_ceil' => 1, 'u_rate' => 1, 'price' => 99]);
        $id = (int) DB::table('allowed_subnets')->insertGetId(['member_id' => $this->memberId, 'subnet_id' => $subnet, 'enabled' => 1, 'charged' => 0, 'speed_class_id' => $speed]);
        return ['id' => $id, 'subnet' => $subnet];
    }

    private function speed(): int
    {
        return (int) DB::table('speed_classes')->insertGetId(['name' => 'Char tarif', 'd_ceil' => 1, 'd_rate' => 1, 'u_ceil' => 1, 'u_rate' => 1, 'price' => 333]);
    }

    // ── zakládání, číslování, události ───────────────────────────────────────

    public function test_cislovani_a_udalosti_zalozeni(): void
    {
        DB::connection('contracts')->table('contracts')->where('id', $this->contractId)->update(['addon' => 1]);  // legacy dodatek č. 1

        $t = $this->svc->createTariffChangeAddon($this->contractId, $this->speed());
        $s = $this->svc->createServiceAddon($this->contractId, $fee = $this->serviceFeeId(), 'remove');
        $c = $this->svc->createConnectionPointAddon($this->contractId, ($p = $this->place())['id'], 'add', '  Adresa 5  ');

        $this->assertSame([2, 3, 4], [(int) $t->addon_no, (int) $s->addon_no, (int) $c->addon_no], 'navazuje i na legacy dodatek č. 1');
        foreach ([$t, $s, $c] as $a) {
            $this->assertSame(['draft', now()->toDateString(), $this->login], [$a->status, $a->effective_date->toDateString(), $a->created_by]);
        }
        $this->assertSame(['Char tarif', 333.0], [$t->new_speed_name, (float) $t->new_price]);
        $this->assertSame(['Char služba', 50.0, 'remove', $fee], [$s->service_name, (float) $s->service_price, $s->service_action, (int) $s->fee_id]);
        $this->assertSame(['Adresa 5', 99.0, 'Char rychlost', $p['id']], [$c->service_name, (float) $c->service_price, $c->place_speed_name, (int) $c->allowed_subnet_id]);

        $this->assertSame([['addon_id' => $t->id, 'by' => $this->login]], $this->events('tariff_addon_created')->all());
        $this->assertSame([['addon_id' => $s->id, 'action' => 'remove', 'fee_id' => $fee, 'by' => $this->login]], $this->events('service_addon_created')->all());
        $this->assertSame([['addon_id' => $c->id, 'action' => 'add', 'allowed_subnet_id' => $p['id'], 'by' => $this->login]], $this->events('connection_point_addon_created')->all());
    }

    public function test_zalozeni_odmitnuto(): void
    {
        $unsigned = (int) DB::connection('contracts')->table('contracts')->insertGetId(['member_id' => $this->memberId, 'contract_no' => 'SML-CHAR-U-' . uniqid(), 'status' => 'draft', 'created_at' => now()]);
        $this->assertNull($this->svc->createTariffChangeAddon($unsigned, $this->speed()), 'nepodepsaná smlouva');
        $this->assertNull($this->svc->createTariffChangeAddon($this->contractId, 999999999), 'neexistující rychlost');
        $this->assertNull($this->svc->createServiceAddon($this->contractId, $this->serviceFeeId(), 'jinak'), 'neznámá akce');
        $this->assertNull($this->svc->createServiceAddon($this->contractId, (int) DB::table('fees')->insertGetId(['readonly' => 0, 'fee' => 1, 'from' => '2000-01-01', 'to' => '9999-12-31', 'type_id' => 35, 'name' => 'x', 'special_type_id' => null])), 'poplatek není služba');
        $this->assertNull($this->svc->createConnectionPointAddon($this->contractId, $this->place()['id'], 'add', '   '), 'prázdná adresa');
        $foreign = (int) DB::table('allowed_subnets')->insertGetId(['member_id' => (int) DB::table('members')->where('id', '<>', $this->memberId)->where('id', '>', 1)->value('id'),
            'subnet_id' => $this->place()['subnet'], 'enabled' => 1]);
        $this->assertNull($this->svc->createConnectionPointAddon($this->contractId, $foreign, 'add', 'A'), 'místo jiného člena');
        $this->assertSame(0, ContractAddon::where('contract_id', $unsigned)->count() + ContractAddon::where('contract_id', $this->contractId)->count());
    }

    // ── seznamy, načtení, PDF cesta, mazání ──────────────────────────────────

    public function test_seznamy_nacteni_cesta_a_mazani(): void
    {
        $t1 = $this->svc->createTariffChangeAddon($this->contractId, $this->speed());
        $t2 = $this->svc->createTariffChangeAddon($this->contractId, $this->speed());
        $s  = $this->svc->createServiceAddon($this->contractId, $this->serviceFeeId());
        $c  = $this->svc->createConnectionPointAddon($this->contractId, $this->place()['id'], 'add', 'A');

        $this->assertSame([$t2->id, $t1->id], $this->svc->tariffAddons($this->contractId)->pluck('id')->all(), 'nejnovější první');
        $this->assertSame([$s->id], $this->svc->serviceAddons($this->contractId)->pluck('id')->all());
        $this->assertSame([$c->id], $this->svc->connectionPointAddons($this->contractId)->pluck('id')->all());
        $this->assertNull($this->svc->getTariffAddon($s->id), 'načtení hlídá typ');
        $this->assertNull($this->svc->getServiceAddon($c->id));
        $this->assertNull($this->svc->getConnectionPointAddon($t1->id));
        $this->assertSame($c->id, $this->svc->getConnectionPointAddon($c->id)->id);

        $base = rtrim((string) config('services.contracts.storage', '/var/www/contract-app/storage/contracts'), '/');
        $this->assertNull($this->svc->tariffAddonPdfPath($t1));
        $t1->pdf_path = 'addons/x.pdf';
        $this->assertSame($base . '/addons/x.pdf', $this->svc->tariffAddonPdfPath($t1));
        $t1->pdf_path = '/abs/x.pdf';
        $this->assertSame('/abs/x.pdf', $this->svc->serviceAddonPdfPath($t1));

        $file = tempnam(sys_get_temp_dir(), 'charaddondel');
        $t2->update(['pdf_path' => $file]);
        foreach ([[$t2, 'deleteTariffAddon', 'tariff_addon_deleted'], [$s, 'deleteServiceAddon', 'service_addon_deleted'], [$c, 'deleteConnectionPointAddon', 'connection_point_addon_deleted']] as [$a, $m, $ev]) {
            $this->assertTrue($this->svc->{$m}($a));
            $this->assertNull(ContractAddon::find($a->id));
            $this->assertSame([['addon_id' => $a->id, 'by' => $this->login]], $this->events($ev)->all());
        }
        $this->assertFileDoesNotExist($file, 'návrh s PDF → soubor smazán');

        $t1->update(['status' => 'signed']);
        $this->assertFalse($this->svc->deleteTariffAddon($t1), 'podepsaný nelze smazat');
        $this->assertNotNull(ContractAddon::find($t1->id));
    }

    // ── podpis ───────────────────────────────────────────────────────────────

    public function test_podpis_tri_typy_otisk_a_ucinnost(): void
    {
        DB::table('members')->where('id', $this->memberId)->update(['speed_class_id' => null]);
        $t = $this->svc->createTariffChangeAddon($this->contractId, $speed = $this->speed());
        $s = $this->svc->createServiceAddon($this->contractId, $this->serviceFeeId());
        $c = $this->svc->createConnectionPointAddon($this->contractId, ($p = $this->place())['id'], 'add', 'A');
        DB::connection('contracts')->table('contract_addons')->whereIn('id', [$t->id, $s->id, $c->id])->update(['effective_date' => '2020-01-01']);
        $sha = hash('sha256', '%PDF-char');

        foreach ([['signTariffAddon', $t, 'tariff_addon_signed'], ['signServiceAddon', $s, 'service_addon_signed'], ['signConnectionPointAddon', $c, 'connection_point_addon_signed']] as [$m, $a, $ev]) {
            $signed = $this->svc->{$m}(ContractAddon::find($a->id), $this->pdf);
            $this->assertSame(['signed', now()->toDateString(), $this->pdfFile], [$signed->status, $signed->effective_date->toDateString(), $signed->pdf_path], $m);
            $this->assertNotNull($signed->signed_at);
            $this->assertSame([['addon_id' => $a->id, 'sha256' => $sha]], $this->events($ev)->all());
        }

        $this->assertSame($speed, (int) DB::table('members')->where('id', $this->memberId)->value('speed_class_id'));
        $this->assertSame([['addon_id' => $t->id, 'member_id' => $this->memberId, 'old_speed_class_id' => null, 'new_speed_class_id' => $speed]], $this->events('tariff_addon_applied')->all());
        $this->assertSame(1, $this->events('service_addon_applied')->count());
        $this->assertSame([['addon_id' => $c->id, 'member_id' => $this->memberId, 'allowed_subnet_id' => $p['id'], 'charged' => true]], $this->events('connection_point_addon_applied')->all());

        // stejný tarif / už aktivní služba / už účtované místo → bez další události
        $t2 = $this->svc->createTariffChangeAddon($this->contractId, $speed);
        $this->svc->signTariffAddon($t2, $this->pdf);
        $s2 = $this->svc->createServiceAddon($this->contractId, (int) $s->fee_id);
        $this->svc->signServiceAddon($s2, $this->pdf);
        $c2 = $this->svc->createConnectionPointAddon($this->contractId, $p['id'], 'add', 'A');
        $this->svc->signConnectionPointAddon($c2, $this->pdf);
        $this->assertSame([1, 1, 1], [$this->events('tariff_addon_applied')->count(), $this->events('service_addon_applied')->count(), $this->events('connection_point_addon_applied')->count()]);
    }

    // ── vydání zrušení (bez podpisu zákazníka) ──────────────────────────────

    public function test_vydani_zruseni_sluzby_a_mista_s_emailem(): void
    {
        $fee = $this->serviceFeeId();
        DB::table('members_fees')->insert(['fee_id' => $fee, 'member_id' => $this->memberId, 'activation_date' => '2020-01-01', 'deactivation_date' => '9999-12-31', 'priority' => 1, 'comment' => 'c']);
        $p = $this->place();
        DB::table('allowed_subnets')->where('id', $p['id'])->update(['charged' => 1]);
        $no = DB::connection('contracts')->table('contracts')->where('id', $this->contractId)->value('contract_no');

        $s = $this->svc->issueServiceRemoval($this->svc->createServiceAddon($this->contractId, $fee, 'remove'), $this->pdf);
        $c = $this->svc->issueConnectionPointRemoval($this->svc->createConnectionPointAddon($this->contractId, $p['id'], 'remove', 'Adresa'), $this->pdf);

        $this->assertSame(['signed', 'signed'], [$s->status, $c->status]);
        $this->assertSame(now()->toDateString(), DB::table('members_fees')->where('member_id', $this->memberId)->where('fee_id', $fee)->value('deactivation_date'));
        $this->assertSame(0, (int) DB::table('allowed_subnets')->where('id', $p['id'])->value('charged'));
        $this->assertSame(1, $this->events('service_addon_removal_issued')->count());
        $this->assertSame(['addon_id' => $s->id, 'member_id' => $this->memberId, 'fee_id' => $fee, 'until' => now()->toDateString(), 'count' => 1], $this->events('service_addon_removal_applied')->first());
        $this->assertSame(['addon_id' => $c->id, 'member_id' => $this->memberId, 'allowed_subnet_id' => $p['id'], 'charged' => false], $this->events('connection_point_addon_removal_applied')->first());

        foreach ([['Zrušení služby ke smlouvě ', 'dodatek-sluzba-zruseni-', 'service_addon_removal_email_sent', $s], ['Zrušení přípojného místa ke smlouvě ', 'dodatek-misto-zruseni-', 'connection_point_addon_removal_email_sent', $c]] as [$subj, $att, $ev, $a]) {
            $mail = DB::table('email_queues')->where('to', 'charaddon@example.invalid')->where('subject', 'CHAR :: ' . $subj . $no)->first();
            $this->assertNotNull($mail, $subj);
            $this->assertStringContainsString('s účinností od ' . now()->format('d.m.Y'), $mail->body);
            $this->assertSame([$att . $no . '.pdf', $this->pdfFile], [
                DB::table('email_queue_attachments')->where('email_queue_id', $mail->id)->value('name'),
                DB::table('email_queue_attachments')->where('email_queue_id', $mail->id)->value('path'),
            ]);
            $this->assertSame([['to' => 'charaddon@example.invalid', 'addon_id' => $a->id]], $this->events($ev)->all());
        }
    }

    public function test_vydani_zruseni_odmitnuto(): void
    {
        $add = $this->svc->createServiceAddon($this->contractId, $this->serviceFeeId(), 'add');
        $this->assertNull($this->svc->issueServiceRemoval($add, $this->pdf), 'přidání se nevydává');
        $cp = $this->svc->createConnectionPointAddon($this->contractId, $this->place()['id'], 'remove', 'A');
        $this->assertNull($this->svc->issueServiceRemoval($cp, $this->pdf), 'jiný typ');
        $cp->update(['status' => 'signed']);
        $this->assertNull($this->svc->issueConnectionPointRemoval($cp, $this->pdf), 'už vydané');

        // bez e-mailu smluvní strany se zrušení vydá, jen se nepošle
        DB::connection('contracts')->table('contract_parties')->where('contract_id', $this->contractId)->update(['email' => '']);
        $r = $this->svc->issueConnectionPointRemoval($this->svc->createConnectionPointAddon($this->contractId, $this->place()['id'], 'remove', 'B'), $this->pdf);
        $this->assertSame('signed', $r->status);
        $this->assertSame(0, $this->events('connection_point_addon_removal_email_sent')->count());
    }
}
