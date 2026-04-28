<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractEvent;
use App\Models\ContractParty;
use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ContractService
{
    private string $smlouvyUrl;
    private string $tokenSecret;
    private string $storageBase;

    public function __construct()
    {
        $this->smlouvyUrl  = rtrim(env('CONTRACTS_SMLOUVY_URL', 'https://smlouvy.pvfree.net'), '/');
        $this->tokenSecret = env('CONTRACTS_TOKEN_SECRET', '');
        $this->storageBase = rtrim(env('CONTRACTS_STORAGE', '/var/www/contract-app/storage/contracts'), '/');
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

        $contract = Contract::create([
            'member_id'   => $member->id,
            'contract_no' => $contractNo,
            'status'      => 'draft',
            'phone'       => $phone,
        ]);

        ContractParty::create([
            'contract_id'          => $contract->id,
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
            'speed_name'           => $speedName,
            'variable_symbol'      => $vs,
            'price'                => 320.00,
            'phone'                => $phone,
            'email'                => $email,
        ]);

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

    public function issueAccessLink(int $contractId): string
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

        return $this->smlouvyUrl . '/contract.html?t=' . $token;
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

    public function sendAddonLink(int $contractId): string
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

        return $this->smlouvyUrl . '/addon.html?t=' . $token;
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
}
