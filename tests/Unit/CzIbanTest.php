<?php

namespace Tests\Unit;

use App\Services\PaymentQrService;
use Closure;
use Tests\TestCase;

/**
 * Dopočet českého IBANu z čísla účtu + kódu banky (mod-97).
 * Čistý výpočet bez DB — private metoda czIban() se volá přes Closure::bind.
 */
class CzIbanTest extends TestCase
{
    private Closure $czIban;

    protected function setUp(): void
    {
        parent::setUp();
        $svc = app(PaymentQrService::class);
        $this->czIban = Closure::bind(
            fn (string $acc, string $bank) => $this->czIban($acc, $bank),
            $svc,
            PaymentQrService::class
        );
    }

    /** Ověří IBAN podle ISO 13616: přesun prvních 4 znaků na konec, písmena→čísla, mod 97 == 1. */
    private function isValidIban(string $iban): bool
    {
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $numeric = '';
        foreach (str_split($rearranged) as $ch) {
            $numeric .= ctype_alpha($ch) ? (string) (ord(strtoupper($ch)) - 55) : $ch;
        }
        return bcmod($numeric, '97') === '1';
    }

    public function test_znamy_priklad(): void
    {
        // Kanonický příklad: 19-2000145399/0800 → CZ6508000000192000145399
        $this->assertSame('CZ6508000000192000145399', ($this->czIban)('19-2000145399', '0800'));
    }

    public function test_vypocteny_iban_projde_mod97_kontrolou(): void
    {
        $cases = [
            ['2200476563', '2010'],   // bez prefixu
            ['19-2000145399', '0800'],// s prefixem
            ['123456789', '0100'],
            ['1', '0300'],
        ];
        foreach ($cases as [$acc, $bank]) {
            $iban = ($this->czIban)($acc, $bank);
            $this->assertNotNull($iban, "IBAN pro $acc/$bank se má dopočítat");
            $this->assertStringStartsWith('CZ', $iban);
            $this->assertTrue($this->isValidIban($iban), "IBAN $iban musí projít mod-97 kontrolou");
        }
    }

    public function test_neplatny_kod_banky_vraci_null(): void
    {
        $this->assertNull(($this->czIban)('2200476563', '80'));   // kód banky není 4 číslice
        $this->assertNull(($this->czIban)('2200476563', 'abcd')); // nečíselný
    }

    public function test_prazdne_cislo_uctu_vraci_null(): void
    {
        $this->assertNull(($this->czIban)('', '0800'));
    }
}
