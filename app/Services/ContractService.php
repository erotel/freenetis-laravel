<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractAddon;
use App\Models\ContractEvent;
use App\Models\ContractParty;
use App\Models\EmailQueue;
use App\Models\EmailQueueAttachment;
use App\Models\Member;
use App\Models\Message;
use App\Models\Setting;
use App\Models\User;
use App\Services\Contracts\PdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContractService
{
    private string $smlouvyUrl;
    private string $tokenSecret;
    private string $storageBase;

    public function __construct()
    {
        // Čteme přes config() místo env() — env() vrací null po `php artisan config:cache`,
        // pokud klíč není mapovaný v config/*.php souboru. Mapujeme v config/services.php.
        $this->smlouvyUrl  = rtrim((string) config('services.contracts.smlouvy_url', 'https://smlouvy.pvfree.net'), '/');
        $this->tokenSecret = (string) config('services.contracts.token_secret', '');
        $this->storageBase = rtrim((string) config('services.contracts.storage', '/var/www/contract-app/storage/contracts'), '/');

        // Bezpečnostní guard: prázdný/krátký/placeholder secret = forgeable HMAC tokeny.
        // Hex z `openssl rand -hex 32` má 64 znaků; akceptujeme min. 32.
        if (
            strlen($this->tokenSecret) < 32
            || $this->tokenSecret === 'long_random_hex_64'
        ) {
            throw new \RuntimeException(
                'CONTRACTS_TOKEN_SECRET není nastaven nebo je příliš krátký (min. 32 znaků). '
                . 'Vygeneruj např. `openssl rand -hex 32` a nastav v .env, pak spusť `php artisan config:cache`.'
            );
        }
    }

    public function getByMemberId(int $memberId): ?Contract
    {
        return Contract::where('member_id', $memberId)
            ->orderByDesc('id')
            ->first();
    }

    public function getByVs(string $vs): ?Contract
    {
        $party = ContractParty::where('variable_symbol', $vs)->first();
        return $party?->contract()->first();
    }

    public function createContract(Member $member): Contract
    {
        $contractNo = $this->generateContractNo((int) $member->id);
        $partyData  = $this->buildPartyData($member);

        $contract = Contract::create([
            'member_id'   => $member->id,
            'contract_no' => $contractNo,
            'status'      => 'draft',
            'phone'       => $partyData['phone'],
        ]);

        ContractParty::create($partyData + ['contract_id' => $contract->id]);

        ContractEvent::create([
            'contract_id' => $contract->id,
            'event'       => 'created',
            'meta_json'   => json_encode([
                'member_id' => $member->id,
                'by'        => 'admin',
                'login'     => auth()->user()?->login,
            ]),
        ]);

        return $contract;
    }

    /**
     * Přepiš snapshot v `contract_parties` živými daty ze `members`. Použití: admin
     * najde chybu v údajích (např. číslo popisné), opraví v editaci člena a chce,
     * ať se to promítne i do nepodepsané smlouvy — bez ztráty `contract_no`.
     * Bezpečné jen pro `draft` (v otp_sent/otp_verified už zákazník viděl staré PDF
     * / dostal kód, admin má smlouvu zrušit a vytvořit novou).
     */
    public function refreshPartyFromMember(Contract $contract): bool
    {
        if ($contract->status !== 'draft') {
            return false;
        }

        $member = Member::with([
            'users.contacts.enumType',
            'accounts.variableSymbols',
            'addressPoint.street',
            'addressPoint.town',
            'speedClass',
        ])->find($contract->member_id);

        if (!$member) return false;

        $party = ContractParty::where('contract_id', $contract->id)->orderByDesc('id')->first();
        if (!$party) return false;

        $newData = $this->buildPartyData($member);
        $oldData = $party->only(array_keys($newData));

        $diff = [];
        foreach ($newData as $k => $v) {
            if ((string) ($oldData[$k] ?? '') !== (string) $v) {
                $diff[$k] = ['from' => $oldData[$k] ?? null, 'to' => $v];
            }
        }

        $party->update($newData);

        // phone je duplikovaný i na contracts (používá se pro OTP flow) — synchronizovat
        if (($contract->phone ?? '') !== $newData['phone']) {
            $contract->update(['phone' => $newData['phone']]);
        }

        ContractEvent::create([
            'contract_id' => $contract->id,
            'event'       => 'party_refreshed',
            'meta_json'   => json_encode([
                'by'    => auth()->user()?->login,
                'diff'  => $diff,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        return true;
    }

    /**
     * Zrušení nepodepsané smlouvy (draft/otp_sent/otp_verified). Návrh nemá
     * právní váhu, proto ho rovnou SMAŽEME (dřív se jen nastavil status
     * `canceled` a mrtvé řádky se u člena hromadily). Smazáním se hlavně uvolní
     * číslo smlouvy (= ID člena) pro případnou novou smlouvu — jinak by nová
     * kolidovala s UNIQUE indexem `uq_contract_no`.
     *
     * Child tabulky (contract_parties/events/otps) padnou přes ON DELETE CASCADE.
     * Podepsané (`signed`) a ukončené (`terminated`) smlouvy sem nikdy nespadnou
     * (guard níže) — ty se ruší jen jako `terminated`, zůstávají jako doklad.
     */
    public function cancelContract(Contract $contract, ?string $reason = null): bool
    {
        if (!in_array($contract->status, ['draft', 'otp_sent', 'otp_verified'], true)) {
            return false;
        }

        // Audit stopa mimo mazanou tabulku (řádek i s eventy zmizí).
        logger()->info('Zrušen (smazán) návrh smlouvy', [
            'contract_no' => $contract->contract_no,
            'member_id'   => $contract->member_id,
            'status'      => $contract->status,
            'reason'      => $reason,
            'by'          => auth()->user()?->login,
        ]);

        return (bool) $contract->delete();
    }

    /**
     * Ukončení podepsané smlouvy (výpověď zákazníka) — smlouva zůstává v systému
     * jako právní dokument (PDF), jen dostane stav `terminated` = "Ukončená".
     * Povoleno jen ze stavu `signed`; návrhy se ruší přes cancelContract().
     */
    public function terminateContract(Contract $contract, ?string $reason = null, ?string $terminationDate = null): bool
    {
        if ($contract->status !== 'signed') {
            return false;
        }

        $contract->update(['status' => 'terminated']);

        ContractEvent::create([
            'contract_id' => $contract->id,
            'event'       => 'terminated',
            'meta_json'   => json_encode([
                'by'     => auth()->user()?->login,
                'reason' => $reason,
                'date'   => $terminationDate,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        return true;
    }

    /**
     * Snapshot z živých dat člena → pole pro contract_parties (bez `contract_id`).
     * Používá createContract() i refreshPartyFromMember().
     */
    /**
     * Aktuální tarif člena jako snapshot: ['speed_name','price','price_after_discount','discount_until'].
     * price = ceníková cena tarifu (speed_classes.price), fallback základní poplatek dle typu;
     * zvýhodněná cena = aktivní individuální 'regular member fee' + jeho platnost.
     */
    public function tariffSnapshot(Member $member): array
    {
        return $this->buildTariffSnapshot(
            $member,
            $member->speedClass?->name ?? '',
            $member->speedClass?->price
        );
    }

    /**
     * Jako tariffSnapshot(), ale pro KONKRÉTNÍ (navrhovanou) třídu rychlosti —
     * použití u tarifního dodatku, kde `new_*` je návrh, ne živý stav člena.
     * Zvýhodněná cena (individuální 'regular member fee') je vlastnost člena,
     * ne rychlosti, takže se snímá stejně.
     */
    public function tariffSnapshotForSpeedClass(Member $member, int $speedClassId): array
    {
        $sc = \App\Models\SpeedClass::find($speedClassId);
        return $this->buildTariffSnapshot($member, $sc?->name ?? '', $sc?->price);
    }

    /** Společné jádro snapshotu tarifu — jméno/cena rychlosti + individuální sleva člena. */
    private function buildTariffSnapshot(Member $member, string $speedName, $tarifPrice): array
    {
        $basePrice  = $tarifPrice !== null
            ? (float) $tarifPrice
            : (RegularFeeResolver::defaultFeeByType((int) $member->type) ?? 0.0);

        $today      = now()->toDateString();
        $individual = DB::table('members_fees as mf')
            ->join('fees as f', 'f.id', '=', 'mf.fee_id')
            ->join('enum_types as et', 'et.id', '=', 'f.type_id')
            ->whereRaw("LOWER(et.value) = 'regular member fee'")
            ->where('mf.member_id', $member->id)
            ->where('mf.activation_date', '<=', $today)
            ->where('mf.deactivation_date', '>=', $today)
            ->orderBy('mf.priority')
            ->first(['f.fee', 'mf.deactivation_date']);

        return [
            'speed_name'           => $speedName,
            'price'                => $basePrice,
            'price_after_discount' => $individual !== null ? (float) $individual->fee : null,
            'discount_until'       => $individual !== null ? $individual->deactivation_date : null,
        ];
    }

    /**
     * Vytvoř dodatek „změna tarifu" k podepsané smlouvě jako NÁVRH. `old_*` snímá
     * SOUČASNÝ tarif člena (živý stav = poslední aplikovaný), `new_*` snímá
     * NAVRHOVANÝ tarif (`$newSpeedClassId`) a uloží jeho ID do `new_speed_class_id`.
     * Rychlost člena se NEMĚNÍ — propíše se teprve při podpisu (signTariffAddon).
     * Účinnost = datum podpisu (viz signTariffAddon); zde jen předvyplněná. Repeatable.
     */
    public function createTariffChangeAddon(int $contractId, int $newSpeedClassId): ?ContractAddon
    {
        $contract = Contract::find($contractId);
        if (!$contract || $contract->status !== 'signed') {
            return null;
        }
        $member = Member::with(['speedClass'])->find($contract->member_id);
        if (!$member) {
            return null;
        }
        // Navrhovaná třída rychlosti musí existovat.
        if (!\App\Models\SpeedClass::whereKey($newSpeedClassId)->exists()) {
            return null;
        }

        // Původní = SOUČASNÝ (živý) tarif člena; s apply-on-sign je to zároveň
        // poslední podepsaný stav (rychlost se mění až podpisem dodatku).
        $old = $this->tariffSnapshot($member);
        $new = $this->tariffSnapshotForSpeedClass($member, $newSpeedClassId);

        // Pořadové číslo dodatku — navazuje i na legacy nulový-tarif addon (č. 1).
        $maxNo    = (int) (ContractAddon::where('contract_id', $contractId)->max('addon_no') ?? 0);
        $addonNo  = max($maxNo, $contract->addon ? 1 : 0) + 1;

        $addon = ContractAddon::create([
            'contract_id'              => $contractId,
            'type'                     => 'tariff_change',
            'addon_no'                 => $addonNo,
            'old_speed_name'           => $old['speed_name'],
            'old_price'                => $old['price'],
            'old_price_after_discount' => $old['price_after_discount'],
            'new_speed_name'           => $new['speed_name'],
            'new_price'                => $new['price'],
            'new_price_after_discount' => $new['price_after_discount'],
            'new_speed_class_id'       => $newSpeedClassId,
            'discount_until'           => $new['discount_until'],
            // Účinnost = den podpisu (apply-on-sign). Zde jen předvyplněno na dnešek
            // (náhled), při podpisu se přepíše na skutečné datum (signTariffAddon).
            'effective_date'           => now()->toDateString(),
            'status'                   => 'draft',
            'created_by'               => auth()->user()?->login,
            'created_at'               => now()->format('Y-m-d H:i:s'),
        ]);

        ContractEvent::create([
            'contract_id' => $contractId,
            'event'       => 'tariff_addon_created',
            'meta_json'   => json_encode(['addon_id' => $addon->id, 'by' => auth()->user()?->login]),
        ]);

        return $addon;
    }

    public function deleteTariffAddon(ContractAddon $addon): bool
    {
        if ($addon->status === 'signed') {
            return false;
        }
        $path = $this->tariffAddonPdfPath($addon);
        if ($path && file_exists($path)) {
            @unlink($path);
        }
        $cid = $addon->contract_id;
        $aid = $addon->id;
        $addon->delete();

        ContractEvent::create([
            'contract_id' => $cid,
            'event'       => 'tariff_addon_deleted',
            'meta_json'   => json_encode(['addon_id' => $aid, 'by' => auth()->user()?->login]),
        ]);

        return true;
    }

    public function tariffAddonPdfPath(ContractAddon $addon): ?string
    {
        if (!$addon->pdf_path) {
            return null;
        }
        if (str_starts_with($addon->pdf_path, '/')) {
            return $addon->pdf_path;
        }
        return $this->storageBase . '/' . $addon->pdf_path;
    }

    /** Všechny dodatky „změna tarifu" dané smlouvy (nejnovější první). */
    public function tariffAddons(int $contractId)
    {
        return ContractAddon::where('contract_id', $contractId)
            ->where('type', 'tariff_change')
            ->orderByDesc('id')
            ->get();
    }

    public function getTariffAddon(int $id): ?ContractAddon
    {
        return ContractAddon::where('type', 'tariff_change')->find($id);
    }

    /**
     * Vydá podpisový odkaz pro dodatek. Token nese cid (pro /sign/info + addon OTP)
     * i aid (pro náhled a finalizaci konkrétního dodatku). Odešle e-mail zákazníkovi.
     */
    public function issueTariffAddonLink(int $addonId): array
    {
        $addon = ContractAddon::find($addonId);
        if (!$addon) {
            return ['url' => null, 'email_sent' => false, 'email' => null];
        }
        $contractId = (int) $addon->contract_id;

        $payload = [
            'cid' => $contractId,
            'aid' => $addonId,
            'exp' => time() + (7 * 86400),
            'rnd' => bin2hex(random_bytes(8)),
        ];
        $json  = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $sig   = hash_hmac('sha256', $json, $this->tokenSecret, true);
        $token = $this->b64url($json) . '.' . $this->b64url($sig);

        if ($addon->status === 'draft') {
            $addon->update(['status' => 'otp_sent']);
        }

        ContractEvent::create([
            'contract_id' => $contractId,
            'event'       => 'tariff_addon_link_issued',
            'meta_json'   => json_encode([
                'addon_id' => $addonId,
                'by'       => auth()->user()?->login,
                'token'    => substr($token, 0, 20) . '…',
            ]),
        ]);

        $url   = route('sign.tariff_addon.show', ['t' => $token]);
        $email = $this->queueSignLinkEmail($contractId, $url, true);

        return ['url' => $url, 'email_sent' => $email !== null, 'email' => $email];
    }

    /** Ověří podpisový token dodatku (sig + exp). Vrací addon_id, nebo null. */
    public function verifyTariffAddonToken(string $token): ?int
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) return null;

        [$p64, $s64] = $parts;
        $json = $this->b64urlDec($p64);
        $sig  = $this->b64urlDec($s64);
        if ($json === '' || $sig === '') return null;

        $calc = hash_hmac('sha256', $json, $this->tokenSecret, true);
        if (!hash_equals($calc, $sig)) return null;

        $data = json_decode($json, true);
        if (!is_array($data) || ((int) ($data['exp'] ?? 0)) < time()) return null;

        $aid = (int) ($data['aid'] ?? 0);
        return $aid > 0 ? $aid : null;
    }

    /**
     * Finalizuje dodatek: vygeneruje podepsané PDF, uloží na řádek dodatku
     * (status=signed, signed_at, pdf_path) a zaloguje. Volá se po ověření OTP.
     */
    public function signTariffAddon(ContractAddon $addon, PdfService $pdf, ?Request $request = null): ContractAddon
    {
        // Účinnost = den podpisu (apply-on-sign, bez plánovače). Musí být před
        // renderem PDF, aby dokument nesl skutečné datum účinnosti.
        $addon->effective_date = now()->toDateString();

        $fullpath = $pdf->renderTariffAddonPdf($addon, false, $request);
        $raw      = (string) @file_get_contents($fullpath);
        $sha256   = hash('sha256', $raw);

        $addon->update([
            'status'         => 'signed',
            'signed_at'      => now(),
            'pdf_path'       => $fullpath,
            'effective_date' => $addon->effective_date,
        ]);

        ContractEvent::create([
            'contract_id' => $addon->contract_id,
            'event'       => 'tariff_addon_signed',
            'meta_json'   => json_encode(['addon_id' => $addon->id, 'sha256' => $sha256], JSON_UNESCAPED_UNICODE),
        ]);

        // Aplikace tarifu na člena AŽ TEĎ (po podpisu). Billing (RegularFeeResolver
        // /DeductFees) čte živý stav člena, takže nová cena naskočí automaticky.
        // Vzor: PublicSignController::finalizeContract (aktivace člena po podpisu).
        if ($addon->new_speed_class_id) {
            $contract = Contract::find($addon->contract_id);
            $memberId = $contract?->member_id;
            $member   = $memberId ? Member::find($memberId) : null;
            if ($member) {
                $oldSpeedClassId = $member->speed_class_id;
                if ((int) $oldSpeedClassId !== (int) $addon->new_speed_class_id) {
                    $member->update(['speed_class_id' => $addon->new_speed_class_id]);

                    ContractEvent::create([
                        'contract_id' => $addon->contract_id,
                        'event'       => 'tariff_addon_applied',
                        'meta_json'   => json_encode([
                            'addon_id'            => $addon->id,
                            'member_id'           => $member->id,
                            'old_speed_class_id'  => $oldSpeedClassId,
                            'new_speed_class_id'  => (int) $addon->new_speed_class_id,
                        ], JSON_UNESCAPED_UNICODE),
                    ]);
                }
            }
        }

        return $addon->refresh();
    }

    // ── Dodatek: dodatečná služba (contract_addons, type='additional_service') ──

    /**
     * Vytvoř dodatek „dodatečná služba" k podepsané smlouvě. $action = 'add'|'remove'.
     * Službu (název + cena) snímá z poplatku typu 'additional service' (fees),
     * aby PDF sedělo s tím, co se reálně účtuje. Účinnost = 1. den dalšího měsíce.
     */
    public function createServiceAddon(int $contractId, int $feeId, string $action = 'add'): ?ContractAddon
    {
        $contract = Contract::find($contractId);
        if (!$contract || $contract->status !== 'signed') {
            return null;
        }
        if (!in_array($action, ['add', 'remove'], true)) {
            return null;
        }

        // Poplatek musí být typu „additional service" (dodatečná služba).
        $fee = DB::table('fees as f')
            ->join('enum_types as et', 'et.id', '=', 'f.type_id')
            ->whereRaw("LOWER(et.value) = 'additional service'")
            ->where('f.id', $feeId)
            ->first(['f.name', 'f.fee']);
        if (!$fee) {
            return null;
        }

        $maxNo   = (int) (ContractAddon::where('contract_id', $contractId)->max('addon_no') ?? 0);
        $addonNo = max($maxNo, $contract->addon ? 1 : 0) + 1;

        $addon = ContractAddon::create([
            'contract_id'    => $contractId,
            'type'           => 'additional_service',
            'addon_no'       => $addonNo,
            'service_name'   => $fee->name !== '' ? $fee->name : 'Dodatečná služba',
            'service_price'  => (float) $fee->fee,
            'service_action' => $action,
            'effective_date' => now()->startOfMonth()->addMonth()->toDateString(),
            'status'         => 'draft',
            'created_by'     => auth()->user()?->login,
            'created_at'     => now()->format('Y-m-d H:i:s'),
        ]);

        ContractEvent::create([
            'contract_id' => $contractId,
            'event'       => 'service_addon_created',
            'meta_json'   => json_encode([
                'addon_id' => $addon->id,
                'action'   => $action,
                'fee_id'   => $feeId,
                'by'       => auth()->user()?->login,
            ]),
        ]);

        return $addon;
    }

    public function deleteServiceAddon(ContractAddon $addon): bool
    {
        if ($addon->status === 'signed') {
            return false;
        }
        $path = $this->serviceAddonPdfPath($addon);
        if ($path && file_exists($path)) {
            @unlink($path);
        }
        $cid = $addon->contract_id;
        $aid = $addon->id;
        $addon->delete();

        ContractEvent::create([
            'contract_id' => $cid,
            'event'       => 'service_addon_deleted',
            'meta_json'   => json_encode(['addon_id' => $aid, 'by' => auth()->user()?->login]),
        ]);

        return true;
    }

    public function serviceAddonPdfPath(ContractAddon $addon): ?string
    {
        if (!$addon->pdf_path) {
            return null;
        }
        if (str_starts_with($addon->pdf_path, '/')) {
            return $addon->pdf_path;
        }
        return $this->storageBase . '/' . $addon->pdf_path;
    }

    /** Všechny dodatky „dodatečná služba" dané smlouvy (nejnovější první). */
    public function serviceAddons(int $contractId)
    {
        return ContractAddon::where('contract_id', $contractId)
            ->where('type', 'additional_service')
            ->orderByDesc('id')
            ->get();
    }

    public function getServiceAddon(int $id): ?ContractAddon
    {
        return ContractAddon::where('type', 'additional_service')->find($id);
    }

    /**
     * Vydá podpisový odkaz pro dodatek služby. Token nese cid (pro /sign OTP)
     * i aid (pro náhled a finalizaci). Odešle e-mail zákazníkovi.
     * Jen pro type='additional_service' a service_action='add' (zrušení = fáze C, bez OTP).
     */
    public function issueServiceAddonLink(int $addonId): array
    {
        $addon = ContractAddon::where('type', 'additional_service')->find($addonId);
        if (!$addon || $addon->service_action !== 'add') {
            return ['url' => null, 'email_sent' => false, 'email' => null];
        }
        $contractId = (int) $addon->contract_id;

        $payload = [
            'cid' => $contractId,
            'aid' => $addonId,
            'exp' => time() + (7 * 86400),
            'rnd' => bin2hex(random_bytes(8)),
        ];
        $json  = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $sig   = hash_hmac('sha256', $json, $this->tokenSecret, true);
        $token = $this->b64url($json) . '.' . $this->b64url($sig);

        if ($addon->status === 'draft') {
            $addon->update(['status' => 'otp_sent']);
        }

        ContractEvent::create([
            'contract_id' => $contractId,
            'event'       => 'service_addon_link_issued',
            'meta_json'   => json_encode([
                'addon_id' => $addonId,
                'by'       => auth()->user()?->login,
                'token'    => substr($token, 0, 20) . '…',
            ]),
        ]);

        $url   = route('sign.service_addon.show', ['t' => $token]);
        $email = $this->queueSignLinkEmail($contractId, $url, true);

        return ['url' => $url, 'email_sent' => $email !== null, 'email' => $email];
    }

    /** Ověří podpisový token dodatku služby (sig + exp). Vrací addon_id, nebo null. */
    public function verifyServiceAddonToken(string $token): ?int
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) return null;

        [$p64, $s64] = $parts;
        $json = $this->b64urlDec($p64);
        $sig  = $this->b64urlDec($s64);
        if ($json === '' || $sig === '') return null;

        $calc = hash_hmac('sha256', $json, $this->tokenSecret, true);
        if (!hash_equals($calc, $sig)) return null;

        $data = json_decode($json, true);
        if (!is_array($data) || ((int) ($data['exp'] ?? 0)) < time()) return null;

        $aid = (int) ($data['aid'] ?? 0);
        return $aid > 0 ? $aid : null;
    }

    /**
     * Finalizuje dodatek služby: vygeneruje podepsané PDF, uloží na řádek
     * (status=signed, signed_at, pdf_path) a zaloguje. Volá se po ověření OTP.
     */
    public function signServiceAddon(ContractAddon $addon, PdfService $pdf, ?Request $request = null): ContractAddon
    {
        $fullpath = $pdf->renderServiceAddonPdf($addon, false, $request);
        $raw      = (string) @file_get_contents($fullpath);
        $sha256   = hash('sha256', $raw);

        $addon->update([
            'status'    => 'signed',
            'signed_at' => now(),
            'pdf_path'  => $fullpath,
        ]);

        ContractEvent::create([
            'contract_id' => $addon->contract_id,
            'event'       => 'service_addon_signed',
            'meta_json'   => json_encode(['addon_id' => $addon->id, 'sha256' => $sha256], JSON_UNESCAPED_UNICODE),
        ]);

        return $addon->refresh();
    }

    /**
     * Vydá dodatek „zrušení služby" bez podpisu zákazníka (poskytovatel).
     * Zrušení jen snižuje závazek účastníka, proto nevyžaduje OTP — poskytovatel
     * vygeneruje PDF, označí jako vydané (signed) a zašle zákazníkovi na e-mail.
     * Jen pro type='additional_service' a service_action='remove'.
     */
    public function issueServiceRemoval(ContractAddon $addon, PdfService $pdf): ?ContractAddon
    {
        if ($addon->type !== 'additional_service' || $addon->service_action !== 'remove' || $addon->status === 'signed') {
            return null;
        }

        $fullpath = $pdf->renderServiceAddonPdf($addon, false, null);
        $raw      = (string) @file_get_contents($fullpath);
        $sha256   = hash('sha256', $raw);

        $addon->update([
            'status'    => 'signed',
            'signed_at' => now(),
            'pdf_path'  => $fullpath,
        ]);

        ContractEvent::create([
            'contract_id' => $addon->contract_id,
            'event'       => 'service_addon_removal_issued',
            'meta_json'   => json_encode(['addon_id' => $addon->id, 'sha256' => $sha256], JSON_UNESCAPED_UNICODE),
        ]);

        $this->emailServiceRemoval($addon);

        return $addon->refresh();
    }

    /** Best-effort: pošle zákazníkovi PDF zrušení služby (nepovinné, neblokuje). */
    private function emailServiceRemoval(ContractAddon $addon): void
    {
        $contract = Contract::find($addon->contract_id);
        if (!$contract) return;

        $party = ContractParty::where('contract_id', $addon->contract_id)->orderByDesc('id')->first();
        $email = trim((string) ($party?->email ?? ''));
        $pdfPath = (string) $addon->pdf_path;
        if ($email === '' || $pdfPath === '' || !is_file($pdfPath)) return;

        try {
            $no      = htmlspecialchars((string) $contract->contract_no, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $svc     = htmlspecialchars((string) $addon->service_name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $subject = $this->buildSubject('Zrušení služby ke smlouvě ' . $contract->contract_no);
            $body    = '<p>Dobrý den,</p>'
                . '<p>v příloze Vám zasíláme dodatek o zrušení služby <strong>' . $svc . '</strong> ke smlouvě <strong>' . $no . '</strong>'
                . ' s účinností od ' . htmlspecialchars((string) $addon->effective_date?->format('d.m.Y'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '.</p>'
                . '<p>Dokument si prosím uschovejte pro svoji evidenci.</p>'
                . '<p>S pozdravem,<br>PVfree.net, z.s.</p>';

            $q = EmailQueue::create([
                'from'        => Setting::get('email_default_email', 'noreply@pvfree.net'),
                'to'          => $email,
                'subject'     => $subject,
                'body'        => $body,
                'state'       => EmailQueue::STATE_NEW,
                'access_time' => now(),
            ]);
            EmailQueueAttachment::create([
                'email_queue_id' => $q->id,
                'path'           => $pdfPath,
                'name'           => 'dodatek-sluzba-zruseni-' . $contract->contract_no . '.pdf',
                'mime'           => 'application/pdf',
                'created_at'     => now(),
            ]);
            ContractEvent::create([
                'contract_id' => $contract->id,
                'event'       => 'service_addon_removal_email_sent',
                'meta_json'   => json_encode(['to' => $email, 'addon_id' => $addon->id], JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Service removal email failed for contract #' . $contract->id . ': ' . $e->getMessage());
        }
    }

    // ── Dodatek: přípojné místo (contract_addons, type='connection_point') ──

    /**
     * Vytvoř dodatek „přípojné místo" k podepsané smlouvě. $action = 'add'|'remove'.
     * Snímá placené místo (allowed_subnets): název podsítě, rychlost a efektivní
     * cenu (fee_override ?? cena rychlosti místa ?? cena rychlosti člena).
     * Účinnost = 1. den dalšího měsíce.
     */
    public function createConnectionPointAddon(int $contractId, int $allowedSubnetId, string $action = 'add', string $address = ''): ?ContractAddon
    {
        $contract = Contract::find($contractId);
        if (!$contract || $contract->status !== 'signed') {
            return null;
        }
        if (!in_array($action, ['add', 'remove'], true)) {
            return null;
        }

        // Snímek místa z výchozí DB (allowed_subnets + podsíť + rychlost). Musí
        // patřit členovi té smlouvy.
        $place = DB::table('allowed_subnets as a')
            ->leftJoin('subnets as s', 's.id', '=', 'a.subnet_id')
            ->leftJoin('speed_classes as sc_place', 'sc_place.id', '=', 'a.speed_class_id')
            ->where('a.id', $allowedSubnetId)
            ->where('a.member_id', $contract->member_id)
            ->first([
                's.name as subnet_name',
                'sc_place.name as place_speed', 'sc_place.price as place_price',
            ]);
        if (!$place) {
            return null;
        }

        // Rychlost a cena z VLASTNÍ rychlosti místa (cena vždy podle rychlosti).
        $speedName = $place->place_speed;
        $fee       = $place->place_price ?? 0;

        // Do dodatku jde ADRESA připojení (ne technický název podsítě). Předvyplňuje
        // se ze zařízení v podsíti, admin ji může upravit — ale musí být vyplněná,
        // jinak dodatek nevytvoříme.
        $address = trim($address);
        if ($address === '') {
            return null;
        }

        $maxNo   = (int) (ContractAddon::where('contract_id', $contractId)->max('addon_no') ?? 0);
        $addonNo = max($maxNo, $contract->addon ? 1 : 0) + 1;

        $addon = ContractAddon::create([
            'contract_id'      => $contractId,
            'type'             => 'connection_point',
            'addon_no'         => $addonNo,
            'service_name'     => $address,
            'service_price'    => (float) $fee,
            'service_action'   => $action,
            'place_speed_name' => $speedName,
            'effective_date'   => now()->startOfMonth()->addMonth()->toDateString(),
            'status'           => 'draft',
            'created_by'       => auth()->user()?->login,
            'created_at'       => now()->format('Y-m-d H:i:s'),
        ]);

        ContractEvent::create([
            'contract_id' => $contractId,
            'event'       => 'connection_point_addon_created',
            'meta_json'   => json_encode([
                'addon_id'          => $addon->id,
                'action'            => $action,
                'allowed_subnet_id' => $allowedSubnetId,
                'by'                => auth()->user()?->login,
            ]),
        ]);

        return $addon;
    }

    public function deleteConnectionPointAddon(ContractAddon $addon): bool
    {
        if ($addon->status === 'signed') {
            return false;
        }
        $path = $this->serviceAddonPdfPath($addon); // cesta k PDF je typově neutrální
        if ($path && file_exists($path)) {
            @unlink($path);
        }
        $cid = $addon->contract_id;
        $aid = $addon->id;
        $addon->delete();

        ContractEvent::create([
            'contract_id' => $cid,
            'event'       => 'connection_point_addon_deleted',
            'meta_json'   => json_encode(['addon_id' => $aid, 'by' => auth()->user()?->login]),
        ]);

        return true;
    }

    /** Všechny dodatky „přípojné místo" dané smlouvy (nejnovější první). */
    public function connectionPointAddons(int $contractId)
    {
        return ContractAddon::where('contract_id', $contractId)
            ->where('type', 'connection_point')
            ->orderByDesc('id')
            ->get();
    }

    public function getConnectionPointAddon(int $id): ?ContractAddon
    {
        return ContractAddon::where('type', 'connection_point')->find($id);
    }

    /**
     * Vydá podpisový odkaz pro dodatek přípojného místa (jen action='add').
     * Token nese cid (pro OTP) i aid (náhled + finalizace). Odešle e-mail.
     */
    public function issueConnectionPointAddonLink(int $addonId): array
    {
        $addon = ContractAddon::where('type', 'connection_point')->find($addonId);
        if (!$addon || $addon->service_action !== 'add') {
            return ['url' => null, 'email_sent' => false, 'email' => null];
        }
        $contractId = (int) $addon->contract_id;

        $payload = [
            'cid' => $contractId,
            'aid' => $addonId,
            'exp' => time() + (7 * 86400),
            'rnd' => bin2hex(random_bytes(8)),
        ];
        $json  = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $sig   = hash_hmac('sha256', $json, $this->tokenSecret, true);
        $token = $this->b64url($json) . '.' . $this->b64url($sig);

        if ($addon->status === 'draft') {
            $addon->update(['status' => 'otp_sent']);
        }

        ContractEvent::create([
            'contract_id' => $contractId,
            'event'       => 'connection_point_addon_link_issued',
            'meta_json'   => json_encode([
                'addon_id' => $addonId,
                'by'       => auth()->user()?->login,
                'token'    => substr($token, 0, 20) . '…',
            ]),
        ]);

        $url   = route('sign.connection_point.show', ['t' => $token]);
        $email = $this->queueSignLinkEmail($contractId, $url, true);

        return ['url' => $url, 'email_sent' => $email !== null, 'email' => $email];
    }

    /** Ověří podpisový token dodatku přípojného místa. Vrací addon_id, nebo null. */
    public function verifyConnectionPointAddonToken(string $token): ?int
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) return null;

        [$p64, $s64] = $parts;
        $json = $this->b64urlDec($p64);
        $sig  = $this->b64urlDec($s64);
        if ($json === '' || $sig === '') return null;

        $calc = hash_hmac('sha256', $json, $this->tokenSecret, true);
        if (!hash_equals($calc, $sig)) return null;

        $data = json_decode($json, true);
        if (!is_array($data) || ((int) ($data['exp'] ?? 0)) < time()) return null;

        $aid = (int) ($data['aid'] ?? 0);
        return $aid > 0 ? $aid : null;
    }

    /** Finalizuje dodatek přípojného místa (podepsané PDF). Volá se po ověření OTP. */
    public function signConnectionPointAddon(ContractAddon $addon, PdfService $pdf, ?Request $request = null): ContractAddon
    {
        $fullpath = $pdf->renderConnectionPointAddonPdf($addon, false, $request);
        $raw      = (string) @file_get_contents($fullpath);
        $sha256   = hash('sha256', $raw);

        $addon->update([
            'status'    => 'signed',
            'signed_at' => now(),
            'pdf_path'  => $fullpath,
        ]);

        ContractEvent::create([
            'contract_id' => $addon->contract_id,
            'event'       => 'connection_point_addon_signed',
            'meta_json'   => json_encode(['addon_id' => $addon->id, 'sha256' => $sha256], JSON_UNESCAPED_UNICODE),
        ]);

        return $addon->refresh();
    }

    /**
     * Vydá dodatek „zrušení přípojného místa" bez podpisu zákazníka
     * (poskytovatel) — zrušení snižuje závazek, proto bez OTP. Jen
     * type='connection_point' a service_action='remove'.
     */
    public function issueConnectionPointRemoval(ContractAddon $addon, PdfService $pdf): ?ContractAddon
    {
        if ($addon->type !== 'connection_point' || $addon->service_action !== 'remove' || $addon->status === 'signed') {
            return null;
        }

        $fullpath = $pdf->renderConnectionPointAddonPdf($addon, false, null);
        $raw      = (string) @file_get_contents($fullpath);
        $sha256   = hash('sha256', $raw);

        $addon->update([
            'status'    => 'signed',
            'signed_at' => now(),
            'pdf_path'  => $fullpath,
        ]);

        ContractEvent::create([
            'contract_id' => $addon->contract_id,
            'event'       => 'connection_point_addon_removal_issued',
            'meta_json'   => json_encode(['addon_id' => $addon->id, 'sha256' => $sha256], JSON_UNESCAPED_UNICODE),
        ]);

        $this->emailConnectionPointRemoval($addon);

        return $addon->refresh();
    }

    /** Best-effort: pošle zákazníkovi PDF zrušení přípojného místa. */
    private function emailConnectionPointRemoval(ContractAddon $addon): void
    {
        $contract = Contract::find($addon->contract_id);
        if (!$contract) return;

        $party = ContractParty::where('contract_id', $addon->contract_id)->orderByDesc('id')->first();
        $email = trim((string) ($party?->email ?? ''));
        $pdfPath = (string) $addon->pdf_path;
        if ($email === '' || $pdfPath === '' || !is_file($pdfPath)) return;

        try {
            $no      = htmlspecialchars((string) $contract->contract_no, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $place   = htmlspecialchars((string) $addon->service_name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $subject = $this->buildSubject('Zrušení přípojného místa ke smlouvě ' . $contract->contract_no);
            $body    = '<p>Dobrý den,</p>'
                . '<p>v příloze Vám zasíláme dodatek o zrušení přípojného místa <strong>' . $place . '</strong> ke smlouvě <strong>' . $no . '</strong>'
                . ' s účinností od ' . htmlspecialchars((string) $addon->effective_date?->format('d.m.Y'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '.</p>'
                . '<p>Dokument si prosím uschovejte pro svoji evidenci.</p>'
                . '<p>S pozdravem,<br>PVfree.net, z.s.</p>';

            $q = EmailQueue::create([
                'from'        => Setting::get('email_default_email', 'noreply@pvfree.net'),
                'to'          => $email,
                'subject'     => $subject,
                'body'        => $body,
                'state'       => EmailQueue::STATE_NEW,
                'access_time' => now(),
            ]);
            EmailQueueAttachment::create([
                'email_queue_id' => $q->id,
                'path'           => $pdfPath,
                'name'           => 'dodatek-misto-zruseni-' . $contract->contract_no . '.pdf',
                'mime'           => 'application/pdf',
                'created_at'     => now(),
            ]);
            ContractEvent::create([
                'contract_id' => $contract->id,
                'event'       => 'connection_point_addon_removal_email_sent',
                'meta_json'   => json_encode(['to' => $email, 'addon_id' => $addon->id], JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Connection-point removal email failed for contract #' . $contract->id . ': ' . $e->getMessage());
        }
    }

    private function buildPartyData(Member $member): array
    {
        $mainUser = $member->users()->where('type', User::MAIN_USER)->first();
        [$phone, $email] = $this->extractContacts($mainUser);

        // Adresa strany (bydliště/sídlo) = adresa člena.
        $ap = $member->addressPoint;
        $street    = $ap?->street?->street ?? '';
        $streetNo  = $ap?->street_number ?? '';
        $town      = $ap?->town?->town ?? '';
        $zip       = $ap?->town?->zip_code ?? '';
        $streetFull = $street ? trim("{$street} {$streetNo}") : '';

        // Místo připojení = adresa prvního zařízení člena; fallback adresa člena.
        $service = $this->resolveServiceAddress($member, $ap);

        $vs = $member->accounts
            ->flatMap(fn($a) => $a->variableSymbols)
            ->first()
            ?->variable_symbol ?? '';

        $snap               = $this->tariffSnapshot($member);
        $speedName          = $snap['speed_name'];
        $basePrice          = $snap['price'];
        $priceAfterDiscount = $snap['price_after_discount'];
        $discountUntil      = $snap['discount_until'];

        // Birthday brát z users.birthday hlavního uživatele — PDF má fallback
        // 'ico → birthday', takže bez něj zůstává buňka u fyzických osob prázdná.
        $birthday = $mainUser?->birthday;
        if ($birthday instanceof \DateTimeInterface) {
            $birthday = $birthday->format('Y-m-d');
        }
        $birthday = (is_string($birthday) && $birthday !== '' && $birthday !== '0000-00-00')
            ? $birthday : null;

        // Snapshot dodatečných služeb (např. veřejná IP) aktivních při vzniku
        // smlouvy — aby byly vidět přímo ve smlouvě (řádky + celková cena).
        $services      = AdditionalServicesResolver::items((int) $member->id, now()->toDateString());
        $servicesTotal = array_sum(array_column($services, 'fee'));

        return [
            'full_name'            => $member->name,
            'street'               => $streetFull,
            'town'                 => $town,
            'service_street'       => $service['street'],
            'service_town'         => $service['town'],
            'service_zip'          => $service['zip'],
            'service_full_address' => $service['full'],
            'country'              => 'CZ',
            'ico'                  => $member->organization_identifier,
            'dic'                  => $member->vat_organization_identifier,
            'birthday'             => $birthday,
            'speed_name'           => $speedName,
            'variable_symbol'      => $vs,
            'price'                => $basePrice,
            'price_after_discount' => $priceAfterDiscount,
            'discount_until'       => $discountUntil,
            'additional_services_json'  => !empty($services) ? $services : null,
            'additional_services_total' => !empty($services) ? $servicesTotal : null,
            'phone'                => $phone,
            'email'                => $email,
        ];
    }

    /**
     * Místo připojení = adresa prvního zařízení člena (nejnižší id, s vyplněnou
     * adresou umístění). Když člen žádné takové zařízení nemá, fallback na jeho
     * vlastní adresu (předaný $memberAp). Vrací ['street','town','zip','full'].
     */
    private function resolveServiceAddress(Member $member, $memberAp): array
    {
        $device = \App\Models\Device::query()
            ->whereIn('user_id', $member->users()->pluck('id'))
            ->whereNotNull('address_point_id')
            ->with(['addressPoint.street', 'addressPoint.town'])
            ->orderBy('id')
            ->first();

        $ap = $device?->addressPoint ?? $memberAp;

        $street    = $ap?->street?->street ?? '';
        $streetNo  = $ap?->street_number ?? '';
        $town      = $ap?->town?->town ?? '';
        $zip       = $ap?->town?->zip_code ?? '';
        $streetFull = $street ? trim("{$street} {$streetNo}") : '';
        $full       = $streetFull ? "{$streetFull}, {$zip} {$town}" : '';

        return ['street' => $streetFull, 'town' => $town, 'zip' => $zip, 'full' => $full];
    }

    /**
     * Ruční úprava místa připojení u návrhu smlouvy. PDF renderuje jen
     * `service_full_address`, takže editujeme primárně to; ostatní service_*
     * pole vyprázdníme, ať se nemíchá starý strukturovaný snapshot s ručním textem.
     */
    public function updateServiceAddress(Contract $contract, string $address): bool
    {
        if ($contract->status !== 'draft') {
            return false;
        }

        $party = ContractParty::where('contract_id', $contract->id)->orderByDesc('id')->first();
        if (!$party) {
            return false;
        }

        $old = (string) $party->service_full_address;
        $new = trim(mb_substr($address, 0, 255));

        $party->update([
            'service_full_address' => $new,
            'service_street'       => null,
            'service_town'         => null,
            'service_zip'          => null,
        ]);

        ContractEvent::create([
            'contract_id' => $contract->id,
            'event'       => 'service_address_edited',
            'meta_json'   => json_encode([
                'by'   => auth()->user()?->login,
                'from' => $old,
                'to'   => $new,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        return true;
    }

    public function issueAccessLink(int $contractId): array
    {
        $payload = [
            'cid' => $contractId,
            'exp' => time() + (7 * 86400),
            'rnd' => bin2hex(random_bytes(8)),
        ];

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $sig  = hash_hmac('sha256', $json, $this->tokenSecret, true);
        $token = $this->b64url($json) . '.' . $this->b64url($sig);

        ContractEvent::create([
            'contract_id' => $contractId,
            'event'       => 'link_issued',
            'meta_json'   => json_encode([
                'by'    => auth()->user()?->login,
                'token' => substr($token, 0, 20) . '…',
            ]),
        ]);

        $url   = route('sign.show', ['t' => $token]);
        $email = $this->queueSignLinkEmail($contractId, $url, false);

        return ['url' => $url, 'email_sent' => $email !== null, 'email' => $email];
    }

    public function createAddon(int $contractId): bool
    {
        $updated = Contract::where('id', $contractId)
            ->where('status', 'signed')
            ->where('addon', 0)
            ->update(['addon' => 1]);

        if ($updated) {
            ContractEvent::create([
                'contract_id' => $contractId,
                'event'       => 'addon_created',
                'meta_json'   => json_encode(['by' => auth()->user()?->login]),
            ]);
        }

        return $updated > 0;
    }

    public function sendAddonLink(int $contractId): array
    {
        $payload = [
            'cid' => $contractId,
            'exp' => time() + (7 * 86400),
            'rnd' => bin2hex(random_bytes(8)),
        ];

        $json  = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $sig   = hash_hmac('sha256', $json, $this->tokenSecret, true);
        $token = $this->b64url($json) . '.' . $this->b64url($sig);

        ContractEvent::create([
            'contract_id' => $contractId,
            'event'       => 'addon_link_issued',
            'meta_json'   => json_encode([
                'by'    => auth()->user()?->login,
                'token' => substr($token, 0, 20) . '…',
            ]),
        ]);

        $url   = route('sign.addon.show', ['t' => $token]);
        $email = $this->queueSignLinkEmail($contractId, $url, true);

        return ['url' => $url, 'email_sent' => $email !== null, 'email' => $email];
    }

    /**
     * Look up the member's email and enqueue the sign-link email.
     * Returns the recipient email if queued, otherwise null.
     */
    private function queueSignLinkEmail(int $contractId, string $url, bool $isAddon): ?string
    {
        $contract = Contract::find($contractId);
        if (!$contract) {
            return null;
        }

        $mainUser = Member::find($contract->member_id)
            ?->users()
            ->where('type', User::MAIN_USER)
            ->first();

        [, $email] = $this->extractContacts($mainUser);
        if (!$email) {
            return null;
        }

        // Šablonu načítáme ze systémové zprávy (messages tabulka) — admin ji
        // edituje v UI Sdělení. Když řádek chybí (např. nezaběhla migrace),
        // padá na hardcoded fallback, aby podpisový flow nikdy neztichnul.
        $type = $isAddon ? Message::CONTRACT_ADDON_SIGN_LINK : Message::CONTRACT_SIGN_LINK;
        $tpl  = Message::where('type', $type)->first();

        if ($tpl && trim((string) $tpl->email_text) !== '') {
            $body    = Message::substitute($tpl->email_text, ['url' => $url]);
            $subject = $this->buildSubject($tpl->name);
        } else {
            $urlHtml = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $what    = $isAddon ? 'dodatku smlouvy' : 'smlouvy';
            $subject = $this->buildSubject('Odkaz pro podpis ' . $what);
            $body = '<p>Dobrý den,</p>'
                . '<p>zasíláme Vám odkaz pro elektronický podpis ' . $what . ':</p>'
                . '<p><a href="' . $urlHtml . '">' . $urlHtml . '</a></p>'
                . '<p>Odkaz je platný 7 dní.</p>'
                . '<p>S pozdravem<br>PVfree.net</p>';
        }

        try {
            EmailQueue::create([
                'from'        => Setting::get('email_default_email', 'noreply@pvfree.net'),
                'to'          => $email,
                'subject'     => $subject,
                'body'        => $body,
                'state'       => EmailQueue::STATE_NEW,
                'access_time' => now(),
            ]);

            ContractEvent::create([
                'contract_id' => $contractId,
                'event'       => 'email_sent',
                'meta_json'   => json_encode([
                    'by'    => auth()->user()?->login,
                    'to'    => $email,
                    'addon' => $isAddon,
                ]),
            ]);

            return $email;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function deleteAddon(Contract $contract): bool
    {
        if ($contract->addon_signed) {
            return false;
        }

        $pdfPath = $this->addonPdfPath($contract);
        if ($pdfPath && file_exists($pdfPath)) {
            @unlink($pdfPath);
        }

        Contract::where('id', $contract->id)->update([
            'addon'          => 0,
            'addon_pdf_path' => null,
            'addon_signed_at' => null,
        ]);

        \Illuminate\Support\Facades\DB::connection('contracts')
            ->table('contract_addon_otps')
            ->where('contract_id', $contract->id)
            ->delete();

        ContractEvent::create([
            'contract_id' => $contract->id,
            'event'       => 'addon_deleted',
            'meta_json'   => json_encode(['by' => auth()->user()?->login]),
        ]);

        return true;
    }

    public function getAddonStatus(Contract $contract): string
    {
        if (!$contract->addon) return 'none';
        if ($contract->addon_signed) return 'signed';
        return 'pending';
    }

    public function addonPdfPath(Contract $contract): ?string
    {
        if (!$contract->addon_pdf_path) return null;

        if (str_starts_with($contract->addon_pdf_path, '/')) {
            return $contract->addon_pdf_path;
        }

        return $this->storageBase . '/' . $contract->addon_pdf_path;
    }

    public function sendOtp(int $contractId): bool
    {
        $contract = Contract::find($contractId);
        if (!$contract) return false;

        $party = ContractParty::where('contract_id', $contractId)->first();
        $phone = $contract->phone ?: $party?->phone;
        if (!$phone) return false;

        $response = $this->httpPost('/api/send_otp.php', [
            'contract_id' => $contractId,
            'phone'       => $phone,
        ]);

        return (bool) ($response['ok'] ?? false);
    }

    public function getStatus(int $contractId): string
    {
        return Contract::find($contractId)?->status ?? 'unknown';
    }

    public function pdfPath(Contract $contract): ?string
    {
        if (!$contract->pdf_path) return null;

        // pdf_path may be absolute or relative to storage base
        if (str_starts_with($contract->pdf_path, '/')) {
            return $contract->pdf_path;
        }

        return $this->storageBase . '/' . $contract->pdf_path;
    }

    public function countUnsigned(): int
    {
        // Odznak musí odpovídat seznamu /contracts, který skrývá smlouvy bývalých
        // členů (typ 15/16). Bez tohoto vyloučení odznak nafukovaly nedokončené
        // návrhy po členech, co už odešli a nikdy je nepodepíšou.
        $formerIds = DB::connection('mysql')
            ->table('members')
            ->whereIn('type', [\App\Helpers\MemberType::FORMER, \App\Helpers\MemberType::FORMER_CUSTOMER])
            ->pluck('id')
            ->all();

        $query = Contract::whereIn('status', ['draft', 'otp_sent', 'otp_verified']);

        if (!empty($formerIds)) {
            $query->where(function ($w) use ($formerIds) {
                $w->whereNull('member_id')
                  ->orWhereNotIn('member_id', $formerIds);
            });
        }

        return $query->count();
    }

    // ── Private helpers ─────────────────────────────────────────────────────

    /**
     * Build the email subject using the same prefix pattern as Controller::sendMessageToMember,
     * so that contract emails match the rest of the system ("PREFIX :: Subject").
     */
    private function buildSubject(string $name): string
    {
        $prefix = (string) Setting::get('email_subject_prefix', '');
        return ($prefix !== '' ? $prefix . ' :: ' : '') . $name;
    }

    /**
     * Číslo smlouvy = ID člena doplněné na 6 cifer: `SML-<rok>-<ID_člena>`
     * (konvence z původního importu). Jeden člen = jedna smlouva; zrušené návrhy
     * se mažou (viz cancelContract), takže se číslo členovi uvolní.
     *
     * Vzácně může být ideální číslo obsazené legacy smlouvou jiného člena
     * (pár signed smluv z 2026 dostalo ještě „ujeté" pořadové číslo). Aby se
     * nespadlo na UNIQUE index `uq_contract_no`, přidá se v takovém případě
     * suffix `-2`, `-3`, …
     */
    private function generateContractNo(int $memberId): string
    {
        $year = date('Y');
        $base = sprintf('SML-%s-%06d', $year, $memberId);

        if (!Contract::where('contract_no', $base)->exists()) {
            return $base;
        }

        for ($i = 2; ; $i++) {
            $candidate = $base . '-' . $i;
            if (!Contract::where('contract_no', $candidate)->exists()) {
                return $candidate;
            }
        }
    }

    private function extractContacts(?User $user): array
    {
        if (!$user) return ['', ''];

        $contacts = $user->contacts()->with('enumType')->get();

        $phone = $contacts
            ->first(fn($c) => str_contains(strtolower($c->enumType?->value ?? ''), 'tel'))
            ?->value ?? '';

        $email = $contacts
            ->first(fn($c) => str_contains(strtolower($c->enumType?->value ?? ''), 'mail'))
            ?->value ?? '';

        return [$phone, $email];
    }

    private function httpPost(string $path, array $data): array
    {
        $url = $this->smlouvyUrl . $path;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $body = curl_exec($ch);
        curl_close($ch);

        return $body ? (json_decode((string) $body, true) ?? []) : [];
    }

    private function b64url(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private function b64urlDec(string $s): string
    {
        $r = base64_decode(strtr($s, '-_', '+/'));
        return $r === false ? '' : $r;
    }

    /**
     * Verify a sign-link access token issued by issueAccessLink/sendAddonLink.
     * Returns the contract ID on success, or null if invalid/expired/tampered.
     */
    public function verifyAccessToken(string $token): ?int
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) return null;

        [$p64, $s64] = $parts;
        $json = $this->b64urlDec($p64);
        $sig  = $this->b64urlDec($s64);
        if ($json === '' || $sig === '') return null;

        $calc = hash_hmac('sha256', $json, $this->tokenSecret, true);
        if (!hash_equals($calc, $sig)) return null;

        $data = json_decode($json, true);
        if (!is_array($data) || ((int) ($data['exp'] ?? 0)) < time()) return null;

        $cid = (int) ($data['cid'] ?? 0);
        return $cid > 0 ? $cid : null;
    }
}
