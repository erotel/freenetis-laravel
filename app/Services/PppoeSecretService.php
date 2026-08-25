<?php

namespace App\Services;

use App\Models\ConnectionRequest;
use App\Models\Iface;
use App\Models\Member;
use App\Models\PppoeSecret;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Generuje / spravuje PPPoE přihlašovací údaje per přípojka (iface).
 *
 * Model (viz onboarding návrh, [[project_pppoe_wpa2_nis2]]):
 *   - username = variabilní symbol člena; má-li člen víc přípojek, přidá se
 *     suffix `-2`, `-3` … (credential je per-iface, username musí být unikátní).
 *   - secret = silné náhodné heslo (cleartext kvůli RADIUS CHAP/MS-CHAPv2;
 *     bezpečnost stojí na síle hesla, ne na utajení username).
 *
 * Idempotentní: opakované volání pro tutéž iface heslo znovu NEGENERUJE (aby se
 * nerozešel s tím, co má zákazník v CPE) — jen zajistí existenci řádku. K rotaci
 * hesla slouží {@see rotateSecret}.
 */
class PppoeSecretService
{
    /** Bezpečná abeceda pro heslo: bez 0/O, 1/l/I a znaků, co drhnou v PPP/RouterOS. */
    private const ALPHABET = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const SECRET_LEN = 16;

    /**
     * Zajistí PPPoE credential pro iface. Když už existuje, vrátí ho beze změny
     * hesla (jen dorovná username, kdyby se změnil VS). Nový → vygeneruje heslo.
     */
    public function ensureForIface(Iface $iface): ?PppoeSecret
    {
        $memberId = $this->memberIdFor($iface);
        if ($memberId === null) {
            Log::warning('PppoeSecret: iface bez člena, credential nevytvořen', ['iface' => $iface->id]);
            return null;
        }

        $existing = PppoeSecret::find($iface->id);
        $username = $this->uniqueUsername($this->variableSymbol($memberId), $iface->id);

        if ($existing) {
            if ($existing->username !== $username) {
                $existing->update(['username' => $username]);
            }
            return $existing;
        }

        return PppoeSecret::create([
            'iface_id' => $iface->id,
            'username' => $username,
            'secret'   => $this->randomSecret(),
            'enabled'  => true,
        ]);
    }

    /**
     * Sestaví (username, secret) pro člena BEZ persistence — použije se při
     * vytvoření žádosti o připojení (uloží se na connection_request a při
     * schválení překlopí do pppoe_secrets přes {@see adoptFromRequest}).
     * username = variabilní symbol, u kolize suffix -2, -3 …
     *
     * @return array{username: string, secret: string}
     */
    public function buildCredential(int $memberId): array
    {
        return [
            'username' => $this->uniqueUsername($this->variableSymbol($memberId)),
            'secret'   => $this->randomSecret(),
        ];
    }

    /**
     * Překlopí credential z žádosti na vzniklý iface (při schválení). Zachová
     * username/heslo, které instalátor už zadal do CPE. Idempotentní přes iface_id.
     */
    public function adoptFromRequest(Iface $iface, string $username, string $secret): PppoeSecret
    {
        return PppoeSecret::updateOrCreate(
            ['iface_id' => $iface->id],
            ['username' => $username, 'secret' => $secret, 'enabled' => true]
        );
    }

    /** Přegeneruje jen heslo (rotace) — username zůstává. Vrací nový secret. */
    public function rotateSecret(Iface $iface): ?PppoeSecret
    {
        $secret = PppoeSecret::find($iface->id);
        if (!$secret) {
            return $this->ensureForIface($iface);
        }
        $secret->update(['secret' => $this->randomSecret()]);
        return $secret;
    }

    /** member_id z iface → device → user. Null když chybí kterýkoli článek. */
    private function memberIdFor(Iface $iface): ?int
    {
        $iface->loadMissing('device.user');
        $mid = $iface->device?->user?->member_id;
        return $mid ? (int) $mid : null;
    }

    /**
     * Variabilní symbol člena (první VS prvního účtu), fallback member_id.
     * Stejný vzor jako InvoiceService/ContractService.
     */
    private function variableSymbol(int $memberId): string
    {
        $member = Member::with('accounts.variableSymbols')->find($memberId);
        $vs = $member?->accounts
            ->flatMap(fn ($a) => $a->variableSymbols)
            ->first()?->variable_symbol;

        return (string) ($vs ?: $memberId);
    }

    /**
     * Vrátí unikátní username: `$base`, nebo `$base-2`, `-3` … když je základ
     * obsazený jinde. Kontroluje pppoe_secrets I čekající žádosti (credential
     * vygenerovaný, zatím nepřeklopený). `$exceptIfaceId` = vlastní iface (kvůli
     * idempotenci při dorovnání username existujícího záznamu).
     */
    private function uniqueUsername(string $base, ?int $exceptIfaceId = null): string
    {
        $candidate = $base;
        $n = 1;
        while ($this->usernameTaken($candidate, $exceptIfaceId)) {
            $n++;
            $candidate = $base . '-' . $n;
        }
        return $candidate;
    }

    private function usernameTaken(string $username, ?int $exceptIfaceId): bool
    {
        $inSecrets = PppoeSecret::where('username', $username)
            ->when($exceptIfaceId !== null, fn ($q) => $q->where('iface_id', '!=', $exceptIfaceId))
            ->exists();
        if ($inSecrets) {
            return true;
        }
        return DB::table('connection_requests')
            ->where('pppoe_username', $username)
            ->where('state', ConnectionRequest::STATE_UNDECIDED)
            ->exists();
    }

    private function randomSecret(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $out = '';
        for ($i = 0; $i < self::SECRET_LEN; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }
        return $out;
    }
}
