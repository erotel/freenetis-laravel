<?php

namespace App\Services;

use App\Models\MfaRecoveryCode;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserMfa;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Dvoufázové přihlášení (MFA) — TOTP (RFC 6238) přes pragmarx/google2fa.
 *
 * Jádro: generování secretu, otpauth URI + QR (endroid), ověření kódu, správa
 * jednorázových záložních kódů. Vlastní přihlašovací flow řeší controllery.
 */
class MfaService
{
    /** Kolik 30s oken tolerovat kolem aktuálního času (drift hodin). */
    private const WINDOW = 1;

    /** Počet záložních kódů generovaných při zapnutí. */
    public const RECOVERY_COUNT = 8;

    private Google2FA $g;

    public function __construct()
    {
        $this->g = new Google2FA();
    }

    public function generateSecret(): string
    {
        return $this->g->generateSecretKey();
    }

    /** otpauth:// URI pro naskenování do authenticator appky. */
    public function otpauthUri(User $user, string $secret): string
    {
        $issuer = Setting::get('title', 'FreenetIS');
        return $this->g->getQRCodeUrl($issuer, (string) $user->login, $secret);
    }

    /** Inline SVG QR kód pro danou otpauth URI. */
    public function qrSvg(string $uri): string
    {
        $qr = QrCode::create($uri)
            ->setEncoding(new Encoding('UTF-8'))
            ->setErrorCorrectionLevel(ErrorCorrectionLevel::Medium)
            ->setSize(240)
            ->setMargin(8);

        return (new SvgWriter())->write($qr)->getString();
    }

    /** Ověří 6místný TOTP kód proti secretu (s tolerancí driftu). */
    public function verifyTotp(string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', (string) $code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        try {
            return (bool) $this->g->verifyKey($secret, $code, self::WINDOW);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Má uživatel dokončené (potvrzené) MFA? */
    public function isEnabled(int $userId): bool
    {
        return UserMfa::where('user_id', $userId)->whereNotNull('confirmed_at')->exists();
    }

    public function getConfirmed(int $userId): ?UserMfa
    {
        return UserMfa::where('user_id', $userId)->whereNotNull('confirmed_at')->first();
    }

    /**
     * Uloží potvrzený secret (dokončení setupu). Vrací UserMfa.
     * Přepíše případnou předchozí konfiguraci.
     */
    public function enable(int $userId, string $secret): UserMfa
    {
        return UserMfa::updateOrCreate(
            ['user_id' => $userId],
            ['totp_secret' => $secret, 'confirmed_at' => now()]
        );
    }

    /** Vypne MFA uživatele (smaže secret i záložní kódy). */
    public function disable(int $userId): void
    {
        UserMfa::where('user_id', $userId)->delete();
        MfaRecoveryCode::where('user_id', $userId)->delete();
    }

    /**
     * Vygeneruje sadu nových záložních kódů (staré zneplatní). Vrací PLAINTEXT
     * kódy (ukázat uživateli jednou); v DB zůstane jen hash.
     *
     * @return string[]
     */
    public function generateRecoveryCodes(int $userId, int $count = self::RECOVERY_COUNT): array
    {
        MfaRecoveryCode::where('user_id', $userId)->delete();

        $plain = [];
        foreach (range(1, $count) as $i) {
            // 10 znaků base32 (bez matoucích 0/1/O/I) ve formátu XXXXX-XXXXX.
            $raw  = Str::upper(Str::random(10));
            $raw  = strtr($raw, ['0' => 'A', '1' => 'B', 'O' => 'C', 'I' => 'D', 'l' => 'E']);
            $code = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
            $plain[] = $code;

            MfaRecoveryCode::create([
                'user_id'    => $userId,
                'code_hash'  => Hash::make($code),
                'created_at' => now(),
            ]);
        }

        return $plain;
    }

    public function unusedRecoveryCount(int $userId): int
    {
        return MfaRecoveryCode::where('user_id', $userId)->whereNull('used_at')->count();
    }

    /** Ověří záložní kód a jednorázově ho spotřebuje. */
    public function verifyAndConsumeRecovery(int $userId, string $code): bool
    {
        $code = strtoupper(trim((string) $code));
        $rows = MfaRecoveryCode::where('user_id', $userId)->whereNull('used_at')->get();

        foreach ($rows as $row) {
            if (Hash::check($code, $row->code_hash)) {
                $row->update(['used_at' => now()]);
                return true;
            }
        }

        return false;
    }
}
