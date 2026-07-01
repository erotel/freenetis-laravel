<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractEvent;
use App\Models\ContractParty;
use App\Models\EmailQueue;
use App\Models\Member;
use App\Models\Message;
use App\Models\Setting;
use App\Models\User;
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
        $contractNo = $this->generateContractNo();
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
     * Zrušení nepodepsané smlouvy (draft/otp_sent/otp_verified). Po zrušení
     * může admin vytvořit novou smlouvu — createContract() explicitně povoluje
     * navazování na `canceled` (viz ContractController::create).
     */
    public function cancelContract(Contract $contract, ?string $reason = null): bool
    {
        if (!in_array($contract->status, ['draft', 'otp_sent', 'otp_verified'], true)) {
            return false;
        }

        $contract->update(['status' => 'canceled']);

        ContractEvent::create([
            'contract_id' => $contract->id,
            'event'       => 'canceled',
            'meta_json'   => json_encode([
                'by'     => auth()->user()?->login,
                'reason' => $reason,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        return true;
    }

    /**
     * Snapshot z živých dat člena → pole pro contract_parties (bez `contract_id`).
     * Používá createContract() i refreshPartyFromMember().
     */
    private function buildPartyData(Member $member): array
    {
        $mainUser = $member->users()->where('type', User::MAIN_USER)->first();
        [$phone, $email] = $this->extractContacts($mainUser);

        $ap = $member->addressPoint;
        $street    = $ap?->street?->street ?? '';
        $streetNo  = $ap?->street_number ?? '';
        $town      = $ap?->town?->town ?? '';
        $zip       = $ap?->town?->zip_code ?? '';
        $streetFull = $street ? trim("{$street} {$streetNo}") : '';
        $fullAddr   = $streetFull ? "{$streetFull}, {$zip} {$town}" : '';

        $vs = $member->accounts
            ->flatMap(fn($a) => $a->variableSymbols)
            ->first()
            ?->variable_symbol ?? '';

        $speedName = $member->speedClass?->name ?? '';

        // Birthday brát z users.birthday hlavního uživatele — PDF má fallback
        // 'ico → birthday', takže bez něj zůstává buňka u fyzických osob prázdná.
        $birthday = $mainUser?->birthday;
        if ($birthday instanceof \DateTimeInterface) {
            $birthday = $birthday->format('Y-m-d');
        }
        $birthday = (is_string($birthday) && $birthday !== '' && $birthday !== '0000-00-00')
            ? $birthday : null;

        return [
            'full_name'            => $member->name,
            'street'               => $streetFull,
            'town'                 => $town,
            'service_street'       => $streetFull,
            'service_town'         => $town,
            'service_zip'          => $zip,
            'service_full_address' => $fullAddr,
            'country'              => 'CZ',
            'ico'                  => $member->organization_identifier,
            'dic'                  => $member->vat_organization_identifier,
            'birthday'             => $birthday,
            'speed_name'           => $speedName,
            'variable_symbol'      => $vs,
            'price'                => 320.00,
            'phone'                => $phone,
            'email'                => $email,
        ];
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
        return Contract::whereIn('status', ['draft', 'otp_sent', 'otp_verified'])->count();
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

    private function generateContractNo(): string
    {
        $year = date('Y');

        $last = Contract::where('contract_no', 'like', "SML-{$year}-%")
            ->orderByDesc('id')
            ->value('contract_no');

        $seq = $last ? ((int) substr($last, -6) + 1) : 1;

        return sprintf('SML-%s-%06d', $year, $seq);
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
