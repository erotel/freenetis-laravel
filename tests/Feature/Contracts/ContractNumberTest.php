<?php

namespace Tests\Feature\Contracts;

use App\Models\Contract;
use App\Services\ContractService;
use Closure;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Číslo smlouvy = ID člena + mazání nepodepsaného návrhu při zrušení.
 * (Chování zavedené ve v2.17.0.)
 */
class ContractNumberTest extends DatabaseTestCase
{
    private ContractService $svc;
    private Closure $gen;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(ContractService::class);
        // generateContractNo() je private — zpřístupníme přes Closure::bind.
        $this->gen = Closure::bind(
            fn (int $memberId) => $this->generateContractNo($memberId),
            $this->svc,
            ContractService::class
        );
    }

    /** Vloží řádek smlouvy na 'contracts' connection a vrátí jeho id. */
    private function insertContract(int $memberId, string $no, string $status): int
    {
        return DB::connection('contracts')->table('contracts')->insertGetId([
            'member_id'  => $memberId,
            'contract_no' => $no,
            'status'     => $status,
            'created_at' => '2026-01-01 00:00:00',
        ]);
    }

    private function no(int $memberId): string
    {
        return sprintf('SML-%s-%06d', date('Y'), $memberId);
    }

    public function test_cislo_je_id_clena(): void
    {
        // Vysoké syntetické ID bez existující smlouvy v letošním roce.
        $mid = 990101;
        $this->assertSame($this->no($mid), ($this->gen)($mid));
    }

    public function test_kolize_prida_suffix(): void
    {
        $mid = 990102;
        $base = $this->no($mid);
        $this->insertContract($mid, $base, 'signed'); // obsadíme base

        $this->assertSame($base . '-2', ($this->gen)($mid));
    }

    public function test_zruseni_navrhu_smaze_radek_a_deti(): void
    {
        $mid = 990103;
        $id  = $this->insertContract($mid, $this->no($mid), 'draft');
        DB::connection('contracts')->table('contract_events')->insert([
            'contract_id' => $id,
            'event'       => 'created',
            'created_at'  => '2026-01-01 00:00:00',
        ]);

        $ok = $this->svc->cancelContract(Contract::find($id), 'test');

        $this->assertTrue($ok);
        $this->assertNull(Contract::find($id), 'řádek smlouvy má být smazán');
        $this->assertSame(
            0,
            DB::connection('contracts')->table('contract_events')->where('contract_id', $id)->count(),
            'child eventy mají padnout přes ON DELETE CASCADE'
        );
    }

    public function test_podepsanou_smlouvu_cancel_nesmaze(): void
    {
        $mid = 990104;
        $id  = $this->insertContract($mid, $this->no($mid), 'signed');

        $ok = $this->svc->cancelContract(Contract::find($id), null);

        $this->assertFalse($ok, 'signed nesmí projít guardem cancelContract');
        $this->assertNotNull(Contract::find($id), 'podepsaná smlouva musí zůstat jako doklad');
    }

    public function test_po_zruseni_je_cislo_zase_volne(): void
    {
        $mid  = 990105;
        $base = $this->no($mid);
        $id   = $this->insertContract($mid, $base, 'draft');

        // Dokud návrh existuje, ideální číslo je obsazené → suffix.
        $this->assertNotSame($base, ($this->gen)($mid));

        $this->svc->cancelContract(Contract::find($id), null);

        // Po smazání se číslo uvolní.
        $this->assertSame($base, ($this->gen)($mid));
    }
}
