<?php

namespace App\Services;

use App\Domains\Contracts\Actions\CreateConnectionPointAddonAction;
use App\Domains\Contracts\Actions\CreateServiceAddonAction;
use App\Domains\Contracts\Actions\CreateTariffAddonAction;
use App\Domains\Contracts\Actions\DeleteAddonAction;
use App\Domains\Contracts\Actions\IssueAddonRemovalAction;
use App\Domains\Contracts\Actions\IssueSignLinkAction;
use App\Domains\Contracts\Actions\SignAddonAction;
use App\Domains\Contracts\Repositories\ContractAddonRepository;
use App\Domains\Contracts\Support\AddonType;
use App\Domains\Contracts\Support\ContractStorage;
use App\Domains\Contracts\Support\TariffSnapshot;
use App\Domains\Contracts\Support\MemberContacts;
use App\Domains\Contracts\Support\SignLinkKind;
use App\Domains\Contracts\Support\SignLinkToken;
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
    private string $storageBase;

    public function __construct()
    {
        // Čteme přes config() místo env() — env() vrací null po `php artisan config:cache`,
        // pokud klíč není mapovaný v config/*.php souboru. Mapujeme v config/services.php.
        $this->smlouvyUrl  = rtrim((string) config('services.contracts.smlouvy_url', 'https://smlouvy.pvfree.net'), '/');
        $this->storageBase = rtrim((string) config('services.contracts.storage', '/var/www/contract-app/storage/contracts'), '/');

        // Bezpečnostní guard tajemství podpisových tokenů (vyhodí výjimku při slabém secretu).
        app(SignLinkToken::class);
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
    public function tariffSnapshot(Member $member): array
    {
        return app(TariffSnapshot::class)->current($member);
    }

    /** Snímek pro navrhovanou třídu rychlosti (new_* tarifního dodatku). */
    public function tariffSnapshotForSpeedClass(Member $member, int $speedClassId): array
    {
        return app(TariffSnapshot::class)->forSpeedClass($member, $speedClassId);
    }

    /** Návrh dodatku „změna tarifu" — viz CreateTariffAddonAction. */
    public function createTariffChangeAddon(int $contractId, int $newSpeedClassId): ?ContractAddon
    {
        return app(CreateTariffAddonAction::class)->handle($contractId, $newSpeedClassId);
    }

    public function deleteTariffAddon(ContractAddon $addon): bool
    {
        return app(DeleteAddonAction::class)->handle($addon, AddonType::TariffChange);
    }

    public function tariffAddonPdfPath(ContractAddon $addon): ?string
    {
        return ContractStorage::path($addon->pdf_path);
    }

    /** Všechny dodatky „změna tarifu" dané smlouvy (nejnovější první). */
    public function tariffAddons(int $contractId)
    {
        return app(ContractAddonRepository::class)->forContract($contractId, AddonType::TariffChange);
    }

    public function getTariffAddon(int $id): ?ContractAddon
    {
        return app(ContractAddonRepository::class)->find($id, AddonType::TariffChange);
    }

    /** Podpisový odkaz dodatku tarifu (token nese cid i aid) + e-mail zákazníkovi. */
    public function issueTariffAddonLink(int $addonId): array
    {
        $addon = ContractAddon::find($addonId);
        if (!$addon) {
            return ['url' => null, 'email_sent' => false, 'email' => null];
        }
        return app(IssueSignLinkAction::class)->handle((int) $addon->contract_id, SignLinkKind::TariffAddon, $addon);
    }

    /** Ověří podpisový token dodatku (sig + exp). Vrací addon_id, nebo null. */
    public function verifyTariffAddonToken(string $token): ?int
    {
        return app(SignLinkToken::class)->addonId($token);
    }

    /** Finalizace po OTP + aplikace tarifu na člena — viz SignAddonAction. */
    public function signTariffAddon(ContractAddon $addon, PdfService $pdf, ?Request $request = null): ContractAddon
    {
        return app(SignAddonAction::class)->handle($addon, AddonType::TariffChange, $pdf, $request);
    }

    // ── Dodatek: dodatečná služba (contract_addons, type='additional_service') ──

    /** Návrh dodatku „dodatečná služba" — viz CreateServiceAddonAction. */
    public function createServiceAddon(int $contractId, int $feeId, string $action = 'add'): ?ContractAddon
    {
        return app(CreateServiceAddonAction::class)->handle($contractId, $feeId, $action);
    }

    public function deleteServiceAddon(ContractAddon $addon): bool
    {
        return app(DeleteAddonAction::class)->handle($addon, AddonType::AdditionalService);
    }

    public function serviceAddonPdfPath(ContractAddon $addon): ?string
    {
        return ContractStorage::path($addon->pdf_path);
    }

    /** Všechny dodatky „dodatečná služba" dané smlouvy (nejnovější první). */
    public function serviceAddons(int $contractId)
    {
        return app(ContractAddonRepository::class)->forContract($contractId, AddonType::AdditionalService);
    }

    public function getServiceAddon(int $id): ?ContractAddon
    {
        return app(ContractAddonRepository::class)->find($id, AddonType::AdditionalService);
    }

    /** Podpisový odkaz dodatku služby (jen přidání; odebrání se nepodepisuje). */
    public function issueServiceAddonLink(int $addonId): array
    {
        $addon = ContractAddon::where('type', 'additional_service')->find($addonId);
        if (!$addon || $addon->service_action !== 'add') {
            return ['url' => null, 'email_sent' => false, 'email' => null];
        }
        return app(IssueSignLinkAction::class)->handle((int) $addon->contract_id, SignLinkKind::ServiceAddon, $addon);
    }

    /** Ověří podpisový token dodatku služby (sig + exp). Vrací addon_id, nebo null. */
    public function verifyServiceAddonToken(string $token): ?int
    {
        return app(SignLinkToken::class)->addonId($token);
    }

    /** Finalizace po OTP + přiřazení služby členovi — viz SignAddonAction. */
    public function signServiceAddon(ContractAddon $addon, PdfService $pdf, ?Request $request = null): ContractAddon
    {
        return app(SignAddonAction::class)->handle($addon, AddonType::AdditionalService, $pdf, $request);
    }

    /** Vydání zrušení služby bez podpisu zákazníka — viz IssueAddonRemovalAction. */
    public function issueServiceRemoval(ContractAddon $addon, PdfService $pdf): ?ContractAddon
    {
        return app(IssueAddonRemovalAction::class)->handle($addon, AddonType::AdditionalService, $pdf);
    }

    // ── Dodatek: přípojné místo (contract_addons, type='connection_point') ──

    /** Návrh dodatku „přípojné místo" — viz CreateConnectionPointAddonAction. */
    public function createConnectionPointAddon(int $contractId, int $allowedSubnetId, string $action = 'add', string $address = ''): ?ContractAddon
    {
        return app(CreateConnectionPointAddonAction::class)->handle($contractId, $allowedSubnetId, $action, $address);
    }

    public function deleteConnectionPointAddon(ContractAddon $addon): bool
    {
        return app(DeleteAddonAction::class)->handle($addon, AddonType::ConnectionPoint);
    }

    /** Všechny dodatky „přípojné místo" dané smlouvy (nejnovější první). */
    public function connectionPointAddons(int $contractId)
    {
        return app(ContractAddonRepository::class)->forContract($contractId, AddonType::ConnectionPoint);
    }

    public function getConnectionPointAddon(int $id): ?ContractAddon
    {
        return app(ContractAddonRepository::class)->find($id, AddonType::ConnectionPoint);
    }

    /** Podpisový odkaz dodatku přípojného místa (jen přidání). */
    public function issueConnectionPointAddonLink(int $addonId): array
    {
        $addon = ContractAddon::where('type', 'connection_point')->find($addonId);
        if (!$addon || $addon->service_action !== 'add') {
            return ['url' => null, 'email_sent' => false, 'email' => null];
        }
        return app(IssueSignLinkAction::class)->handle((int) $addon->contract_id, SignLinkKind::ConnectionPoint, $addon);
    }

    /** Ověří podpisový token dodatku přípojného místa. Vrací addon_id, nebo null. */
    public function verifyConnectionPointAddonToken(string $token): ?int
    {
        return app(SignLinkToken::class)->addonId($token);
    }

    /** Finalizace po OTP + zapnutí účtování místa — viz SignAddonAction. */
    public function signConnectionPointAddon(ContractAddon $addon, PdfService $pdf, ?Request $request = null): ContractAddon
    {
        return app(SignAddonAction::class)->handle($addon, AddonType::ConnectionPoint, $pdf, $request);
    }

    /** Vydání zrušení přípojného místa bez podpisu zákazníka — viz IssueAddonRemovalAction. */
    public function issueConnectionPointRemoval(ContractAddon $addon, PdfService $pdf): ?ContractAddon
    {
        return app(IssueAddonRemovalAction::class)->handle($addon, AddonType::ConnectionPoint, $pdf);
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
        $services      = app(\App\Domains\Billing\Fees\FeePolicy::class)->additionalServiceItems((int) $member->id, now()->toDateString());
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
        return app(IssueSignLinkAction::class)->handle($contractId, SignLinkKind::Contract);
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
        return app(IssueSignLinkAction::class)->handle($contractId, SignLinkKind::LegacyAddon);
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

    /** Předmět s prefixem systému („PREFIX :: Název"). */
    private function buildSubject(string $name): string
    {
        return IssueSignLinkAction::subject($name);
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
        return MemberContacts::of($user);
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

    /**
     * Ověří podpisový token (smlouva i dodatek nesou cid). Vrací id smlouvy,
     * nebo null (neplatný/expirovaný/podvržený).
     */
    public function verifyAccessToken(string $token): ?int
    {
        return app(SignLinkToken::class)->contractId($token);
    }
}
