<?php

namespace App\Services\Contracts;

use App\Models\Contract;
use App\Models\ContractEvent;
use App\Models\Setting;
use App\Services\SmsManagerDriver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OtpService
{
    private const RESEND_CACHE_PREFIX = 'contract_otp_resend:';

    public function sendOtp(Contract $contract, string $phone, ?Request $request = null): array
    {
        return $this->sendOtpInternal($contract, $phone, false, $request);
    }

    public function verifyOtp(Contract $contract, string $otp, ?Request $request = null): array
    {
        return $this->verifyOtpInternal($contract, $otp, false, $request);
    }

    public function sendAddonOtp(Contract $contract, ?Request $request = null): array
    {
        $phone = trim((string) $contract->phone);
        if ($phone === '') {
            return ['ok' => false, 'status' => 422, 'error' => 'U smlouvy není uložené telefonní číslo.'];
        }
        return $this->sendOtpInternal($contract, $phone, true, $request);
    }

    public function verifyAddonOtp(Contract $contract, string $otp, ?Request $request = null): array
    {
        return $this->verifyOtpInternal($contract, $otp, true, $request);
    }

    private function sendOtpInternal(Contract $contract, string $phone, bool $isAddon, ?Request $request): array
    {
        if (!$isAddon && !in_array($contract->status, ['draft', 'otp_sent', 'otp_verified'], true)) {
            return ['ok' => false, 'status' => 409, 'error' => 'Smlouva je již podepsaná.'];
        }

        if (!$this->canSendAgain($contract->id)) {
            return ['ok' => false, 'status' => 429, 'error' => 'Požadavek odeslán, počkejte na sms.'];
        }

        $ttl       = (int) $this->cfg('otp_ttl_min', 5);
        $attempts  = (int) $this->cfg('otp_max_attempts', 5);
        $expiresAt = now()->setTimezone('Europe/Prague')->addMinutes($ttl)->format('Y-m-d H:i:s');
        $clientIp  = $request?->ip() ?? '0.0.0.0';
        $ua        = $request?->userAgent() ?? '';
        $tableOtps = $isAddon ? 'contract_addon_otps' : 'contract_otps';
        $eventName = $isAddon ? 'addon_otp_sent' : 'otp_sent';
        $testMode  = $this->isTestMode();
        $otp       = $testMode ? $this->testCode() : str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $hashHex   = $this->otpHash($otp);

        DB::connection('contracts')->beginTransaction();
        try {
            DB::connection('contracts')->table($tableOtps)->insert([
                'contract_id'   => $contract->id,
                'otp_hash'      => $hashHex,
                'attempts_left' => $attempts,
                'expires_at'    => $expiresAt,
                'created_at'    => now(),
            ]);

            if (!$isAddon) {
                DB::connection('contracts')->table('contracts')
                    ->where('id', $contract->id)
                    ->update(['phone' => $phone, 'status' => 'otp_sent']);
            }

            ContractEvent::create([
                'contract_id' => $contract->id,
                'event'       => $eventName,
                'meta_json'   => json_encode([
                    'phone_mask' => $this->maskPhone($phone),
                    'ip'         => $clientIp,
                    'ua'         => $ua,
                    'test_mode'  => $testMode ? 1 : 0,
                ], JSON_UNESCAPED_UNICODE),
            ]);

            DB::connection('contracts')->commit();
        } catch (\Throwable $e) {
            DB::connection('contracts')->rollBack();
            return ['ok' => false, 'status' => 500, 'error' => 'Server error', 'detail' => $e->getMessage()];
        }

        if (!$testMode) {
            $msgWhat = $isAddon ? 'pro dodatek smlouvy' : '';
            $smsText = trim("Vas overovaci kod {$msgWhat}: {$otp} (plati {$ttl} min)");
            if (!$this->sendSms($phone, $smsText)) {
                return ['ok' => false, 'status' => 502, 'error' => 'SMS se nepodařilo odeslat.'];
            }
        }

        $this->noteSentNow($contract->id);

        return [
            'ok'         => true,
            'phone_mask' => $this->maskPhone($phone),
            'ttl_min'    => $ttl,
            'test_mode'  => $testMode,
        ];
    }

    private function verifyOtpInternal(Contract $contract, string $otp, bool $isAddon, ?Request $request): array
    {
        if (!preg_match('/^\d{6}$/', $otp)) {
            return ['ok' => false, 'status' => 400, 'error' => 'Invalid data'];
        }

        $clientIp  = $request?->ip() ?? '0.0.0.0';
        $ua        = $request?->userAgent() ?? '';
        $tableOtps = $isAddon ? 'contract_addon_otps' : 'contract_otps';
        $eventOk   = $isAddon ? 'addon_otp_verified' : 'otp_verified';
        $eventTry  = $isAddon ? 'addon_otp_attempt'  : 'otp_attempt';
        $hashHex   = $this->otpHash($otp);

        if ($this->isTestMode()) {
            if ($otp !== $this->testCode()) {
                return ['ok' => false, 'status' => 401, 'error' => 'Invalid code (test mode)'];
            }
            DB::connection('contracts')->beginTransaction();
            try {
                if (!$isAddon) {
                    DB::connection('contracts')->table('contracts')
                        ->where('id', $contract->id)
                        ->update(['status' => 'otp_verified']);
                }
                ContractEvent::create([
                    'contract_id' => $contract->id,
                    'event'       => $eventOk,
                    'meta_json'   => json_encode([
                        'ok'        => 1,
                        'ip'        => $clientIp,
                        'ua'        => $ua,
                        'test_mode' => 1,
                    ], JSON_UNESCAPED_UNICODE),
                ]);
                DB::connection('contracts')->commit();
            } catch (\Throwable $e) {
                DB::connection('contracts')->rollBack();
                return ['ok' => false, 'status' => 500, 'error' => 'Server error', 'detail' => $e->getMessage()];
            }
            return ['ok' => true, 'test_mode' => true];
        }

        DB::connection('contracts')->beginTransaction();
        try {
            $row = DB::connection('contracts')->table($tableOtps)
                ->where('contract_id', $contract->id)
                ->where('expires_at', '>', now())
                ->orderByDesc('id')
                ->first();

            if (!$row || (int) $row->attempts_left <= 0) {
                DB::connection('contracts')->rollBack();
                return ['ok' => false, 'status' => 410, 'error' => 'Platnost ověřovacího kódu vypršela.'];
            }

            if (!hash_equals((string) $row->otp_hash, $hashHex)) {
                DB::connection('contracts')->table($tableOtps)
                    ->where('id', $row->id)
                    ->update(['attempts_left' => DB::raw('GREATEST(attempts_left - 1, 0)')]);

                ContractEvent::create([
                    'contract_id' => $contract->id,
                    'event'       => $eventTry,
                    'meta_json'   => json_encode(['ok' => 0, 'ip' => $clientIp, 'ua' => $ua], JSON_UNESCAPED_UNICODE),
                ]);

                DB::connection('contracts')->commit();
                return ['ok' => false, 'status' => 401, 'error' => 'Neplatný ověřovací kód.'];
            }

            // success
            if ($isAddon) {
                DB::connection('contracts')->table($tableOtps)
                    ->where('id', $row->id)
                    ->update(['attempts_left' => 0]);
            } else {
                DB::connection('contracts')->table('contracts')
                    ->where('id', $contract->id)
                    ->update(['status' => 'otp_verified']);
            }

            ContractEvent::create([
                'contract_id' => $contract->id,
                'event'       => $eventOk,
                'meta_json'   => json_encode(['ok' => 1, 'ip' => $clientIp, 'ua' => $ua], JSON_UNESCAPED_UNICODE),
            ]);

            DB::connection('contracts')->commit();
            return ['ok' => true];
        } catch (\Throwable $e) {
            DB::connection('contracts')->rollBack();
            return ['ok' => false, 'status' => 500, 'error' => 'Server error', 'detail' => $e->getMessage()];
        }
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function cfg(string $key, $default = null)
    {
        $v = Setting::get($key, null);
        if ($v === null || $v === '') {
            return config("services.contracts.{$key}", $default);
        }
        return $v;
    }

    private function otpHash(string $otp): string
    {
        $pepper = (string) $this->cfg('otp_pepper', '');
        return hash('sha256', $otp . $pepper);
    }

    private function isTestMode(): bool
    {
        // Hard guard: test mode pouze mimo produkci. Bez tohoto by admin mohl
        // přepnutím Setting::set('otp_test_mode', 1) v DB akceptovat hardcoded
        // OTP "111111" pro každého klienta a obejít celou SMS verifikaci.
        if (app()->environment('production')) {
            return false;
        }

        $v = $this->cfg('otp_test_mode', false);
        $on = in_array(strtolower((string) $v), ['1', 'true', 'yes'], true);

        if ($on) {
            \Illuminate\Support\Facades\Log::warning('OTP test mode is ACTIVE — accepting fixed code, NOT for production!');
        }

        return $on;
    }

    private function testCode(): string
    {
        $c = trim((string) $this->cfg('otp_test_code', '111111'));
        return preg_match('/^\d{6}$/', $c) ? $c : '111111';
    }

    private function maskPhone(string $phone): string
    {
        $tail = substr(preg_replace('/\D/', '', $phone) ?? '', -4);
        return '****' . $tail;
    }

    private function canSendAgain(int $contractId): bool
    {
        return !Cache::has(self::RESEND_CACHE_PREFIX . $contractId);
    }

    private function noteSentNow(int $contractId): void
    {
        $window = (int) $this->cfg('otp_resend_window_sec', 60);
        Cache::put(self::RESEND_CACHE_PREFIX . $contractId, time(), max(1, $window));
    }

    /**
     * Send SMS via FreeNetIS pipeline: log into sms_messages (audit/queue) and dispatch
     * synchronously through SmsManagerDriver (OTP cannot wait for the cron).
     *
     * Credentials read from Settings (sms_password5 = SmsManager API key,
     * sms_sender_number = sender), with .env fallbacks for first-run compatibility.
     */
    private function sendSms(string $to, string $text): bool
    {
        $to = $this->normalizeMsisdn($to);
        if ($to === '') {
            Log::warning('SMS skip: invalid phone number');
            return false;
        }

        // Klíč číst podle aktivního driveru (sms_password{id}) — stejně jako
        // cron SmsSend.php:123. Hardcoded 'sms_password5' (pozůstatek z času,
        // kdy SmsManager měl id=5) klíč nenacházel a OTP SMS hned padaly.
        // env() po config:cache vrací default — používáme config() jako fallback.
        $driverId = (int) Setting::get('sms_driver', SmsManagerDriver::DRIVER_ID);
        $apiKey   = (string) (Setting::get('sms_password' . $driverId) ?: config('services.sms.api_key', ''));
        $sender   = (string) (Setting::get('sms_sender_number')        ?: config('services.sms.sender', ''));
        if ($apiKey === '' || $sender === '') {
            Log::warning("SMS skip: missing API key or sender (sms_password{$driverId} / sms_sender_number)");
            return false;
        }

        $smsId = DB::table('sms_messages')->insertGetId([
            'user_id'   => 1,            // system/admin — public sign flow has no authenticated user
            'stamp'     => now(),
            'send_date' => now(),        // column is NOT NULL; updated after API call to actual send time
            'text'      => mb_substr($text, 0, 800),
            'sender'    => mb_substr($sender, 0, 14),
            'receiver'  => mb_substr($to, 0, 14),
            'driver'    => SmsManagerDriver::DRIVER_ID,
            'type'      => 1,            // SENT (outgoing)
            'state'     => 1,            // SENT_UNSENT (pending)
            'message'   => '',
        ]);

        $driver = new SmsManagerDriver($apiKey);
        $ok     = $driver->send($sender, $to, $text);

        DB::table('sms_messages')->where('id', $smsId)->update([
            'state'     => $ok ? 0 : 2,  // SENT_OK | SENT_FAILED
            'send_date' => now(),
            'message'   => mb_substr((string) ($ok ? $driver->getStatus() : $driver->getError()), 0, 100),
        ]);

        if (!$ok) {
            Log::warning('SMS #' . $smsId . ' failed: ' . $driver->getError());
        }

        return $ok;
    }

    private function normalizeMsisdn(string $phone): string
    {
        $phone = trim($phone);
        if ($phone === '') return '';
        if (str_starts_with($phone, '00')) {
            $phone = '+' . substr($phone, 2);
        }
        $phone = preg_replace('/(?!^\+)[^\d]/', '', $phone) ?? '';
        if ($phone === '') return '';
        if (str_starts_with($phone, '+')) {
            $phone = substr($phone, 1);
        }
        if (preg_match('/^\d{9}$/', $phone)) {
            return '420' . $phone;
        }
        if (preg_match('/^420\d{9}$/', $phone)) {
            return $phone;
        }
        return '';
    }
}
