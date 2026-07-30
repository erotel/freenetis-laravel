<?php

namespace App\Services;

use App\Models\Setting;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Support\Facades\DB;

/**
 * Generátor QR platby (český formát QR Platba / SPAYD) pro člena.
 *
 * Skládá platební údaje z:
 *   - cílového bankovního účtu podle typu člena (config bank_account_member_type_<type>),
 *     IBAN se vezme z bank_accounts.IBAN nebo dopočítá z čísla účtu + kódu banky,
 *   - variabilního symbolu z kreditního účtu člena (account_attribute_id = 221100),
 *   - částky podle aktivního pravidelného členského poplatku (tarifu).
 *
 * Vrací null vždy, když QR nelze bezpečně sestavit (chybí účet/IBAN, VS nebo částka)
 * — volající pak QR prostě nezobrazí.
 */
class PaymentQrService
{
    private const CREDIT_ATTRIBUTE_ID = 221100;

    /**
     * @return array{iban:string,account_number:string,amount:float,vs:string,spayd:string}|null
     */
    public function paymentInfoForMember(int $memberId): ?array
    {
        $member = DB::table('members')->where('id', $memberId)->first(['id', 'type']);
        if (!$member) {
            return null;
        }

        $bank = $this->bankAccountForType((int) $member->type);
        if (!$bank) {
            return null;
        }

        $iban = $this->ibanFor($bank);
        if (!$iban) {
            return null;
        }

        $vs = $this->variableSymbol($memberId);
        if ($vs === '') {
            return null;
        }

        $amount = $this->regularFee($memberId, (int) $member->type);
        if ($amount <= 0) {
            return null;
        }

        $accountNumber = trim(($bank->account_nr ?? '') . '/' . ($bank->bank_nr ?? ''), '/');

        return [
            'iban'           => $iban,
            'account_number' => $accountNumber,
            'amount'         => $amount,
            'vs'             => $vs,
            'spayd'          => $this->buildSpayd($iban, $bank->SWIFT ?? null, $amount, $vs),
        ];
    }

    /** Inline SVG (bez XML prologu) pro vložení přímo do HTML stránky, nebo null. */
    public function svgForMember(int $memberId): ?string
    {
        $info = $this->paymentInfoForMember($memberId);
        if (!$info) {
            return null;
        }
        $svg = (new SvgWriter())->write($this->qr($info['spayd']))->getString();

        // Odstranit XML prolog na začátku, ať jde SVG vložit inline do HTML stránky.
        $svg = preg_replace('/^<\?xml[^>]*\?>\s*/', '', $svg);

        // Endroid vykreslí <svg> s pevnou šířkou/výškou (240px), což přetéká přes
        // layout. Přidáme responzivní styl, aby se QR přizpůsobil kontejneru
        // (CSS má přednost před width/height atributy).
        return preg_replace(
            '/<svg\b/',
            '<svg style="width:100%;height:auto;display:block"',
            $svg,
            1
        );
    }

    /** Binární PNG data pro cid přílohu emailu, nebo null. */
    public function pngBytesForMember(int $memberId): ?string
    {
        $info = $this->paymentInfoForMember($memberId);
        if (!$info) {
            return null;
        }
        return (new PngWriter())->write($this->qr($info['spayd']))->getString();
    }

    // -------------------------------------------------------------------------

    private function qr(string $data): QrCode
    {
        return QrCode::create($data)
            ->setEncoding(new Encoding('UTF-8'))
            ->setErrorCorrectionLevel(ErrorCorrectionLevel::Medium)
            ->setSize(240)
            ->setMargin(8);
    }

    private function buildSpayd(string $iban, ?string $swift, float $amount, string $vs): string
    {
        $acc = $iban . ($swift ? '+' . $swift : '');
        // "QR Platba" jako marker — z bankovního výpisu (zpráva pro příjemce) pak
        // jde poznat, kolik plateb přišlo přes QR kód.
        $msg = $this->sanitizeMsg('QR Platba ' . Setting::get('title', 'FreenetIS') . ' VS' . $vs);

        return sprintf(
            'SPD*1.0*ACC:%s*AM:%s*CC:CZK*X-VS:%s*MSG:%s',
            $acc,
            number_format($amount, 2, '.', ''),
            preg_replace('/\D/', '', $vs),
            $msg
        );
    }

    /** SPAYD: '*' je oddělovač polí; zpráva musí být bez něj a bez diakritiky, max 60 znaků. */
    private function sanitizeMsg(string $s): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($ascii !== false) {
            $s = $ascii;
        }
        $s = str_replace('*', ' ', $s);
        $s = preg_replace('/[^\x20-\x7E]/', '', $s);

        return trim(substr($s, 0, 60));
    }

    private function bankAccountForType(int $type): ?object
    {
        $bankId = (int) Setting::get('bank_account_member_type_' . $type, 0);
        if (!$bankId) {
            return null;
        }
        return DB::table('bank_accounts')
            ->where('id', $bankId)
            ->first(['id', 'account_nr', 'bank_nr', 'IBAN', 'SWIFT']);
    }

    private function ibanFor(object $bank): ?string
    {
        $iban = preg_replace('/\s+/', '', (string) ($bank->IBAN ?? ''));
        if ($iban !== '') {
            return strtoupper($iban);
        }
        return $this->czIban((string) ($bank->account_nr ?? ''), (string) ($bank->bank_nr ?? ''));
    }

    /**
     * Dopočet českého IBANu z čísla účtu (formát [prefix-]číslo) a kódu banky.
     * BBAN = kód banky(4) + prefix(6) + číslo(10); kontrolní číslice mod-97.
     */
    private function czIban(string $accountNr, string $bankNr): ?string
    {
        $bankNr = preg_replace('/\D/', '', $bankNr);
        if (strlen($bankNr) !== 4) {
            return null;
        }

        $prefix = '0';
        $number = $accountNr;
        if (str_contains($accountNr, '-')) {
            [$prefix, $number] = explode('-', $accountNr, 2);
        }
        $prefix = preg_replace('/\D/', '', $prefix);
        $number = preg_replace('/\D/', '', (string) $number);
        if ($number === '') {
            return null;
        }

        $bban  = $bankNr
            . str_pad($prefix, 6, '0', STR_PAD_LEFT)
            . str_pad($number, 10, '0', STR_PAD_LEFT);
        // (BBAN + "CZ" + "00") s C=12, Z=35 → "123500"; kontrola = 98 - mod97
        $check = 98 - (int) bcmod($bban . '123500', '97');

        return 'CZ' . str_pad((string) $check, 2, '0', STR_PAD_LEFT) . $bban;
    }

    private function variableSymbol(int $memberId): string
    {
        $account = DB::table('accounts')
            ->where('member_id', $memberId)
            ->where('account_attribute_id', self::CREDIT_ATTRIBUTE_ID)
            ->first(['id']);
        if (!$account) {
            return '';
        }
        return (string) DB::table('variable_symbols')
            ->where('account_id', $account->id)
            ->orderBy('id')
            ->value('variable_symbol');
    }

    /**
     * Výše aktivního pravidelného členského poplatku (tarifu). Pořadí:
     * individuální (members_fees) → cena tarifu (speed_classes.price) → výchozí
     * poplatek pro typ člena → tarif sdružení (member 1). První tři řeší centrální
     * RegularFeeResolver (shodně s DeductFees), poslední fallback je specifikum QR.
     */
    private function regularFee(int $memberId, int $memberType): float
    {
        $date = now()->toDateString();

        $amount = RegularFeeResolver::amount($memberId, $memberType, $date);
        if ($amount !== null) {
            return $amount;
        }

        // Poslední fallback jen pro QR: tarif sdružení (member 1).
        return (float) (RegularFeeResolver::individualFee(1, $date) ?? 0);
    }
}
