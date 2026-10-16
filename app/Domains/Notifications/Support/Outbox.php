<?php

namespace App\Domains\Notifications\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/** Odchozí fronty e-mailů a SMS (zpracují je email:send-queue / sms:send). */
final class Outbox
{
    public const CONTACT_EMAIL = 20;
    public const CONTACT_PHONE = 21;

    /** Předmět zprávy s prefixem systému („PREFIX :: Název"). */
    public static function subject(string $name): string
    {
        $prefix = Setting::get('email_subject_prefix', '');
        return ($prefix ? $prefix . ' :: ' : '') . $name;
    }

    public static function from(): string
    {
        return Setting::get('email_default_email', 'noreply@freenetis.org');
    }

    /** SMS brána: [driver, odesílatel], nebo null (vypnuto / nenastaveno). */
    public static function smsGateway(): ?array
    {
        $enabled = Setting::get('sms_enabled', '0');
        $driver  = Setting::get('sms_driver', '');
        $sender  = Setting::get('sms_sender_number', '');
        return ($enabled && $driver && $sender) ? [(int) $driver, $sender] : null;
    }

    /** Řádek sms_messages: odeslaná zpráva (type 1) čekající na odeslání (state 1). */
    public static function smsRow(?int $userId, string $now, string $text, string $receiver, array $gateway): array
    {
        return [
            'user_id' => $userId, 'sms_message_id' => null, 'stamp' => $now, 'send_date' => $now, 'text' => $text,
            'sender' => $gateway[1], 'receiver' => $receiver, 'driver' => $gateway[0], 'type' => 1, 'state' => 1,
        ];
    }

    /** Vloží řádky po dávkách (2000 členů by jinak drželo dlouhý lock + paměť). @return int počet */
    public static function insertChunked(string $table, iterable $rows, int $chunk = 500): int
    {
        $n = 0;
        $buffer = [];
        foreach ($rows as $row) {
            $buffer[] = $row;
            if (count($buffer) >= $chunk) {
                DB::table($table)->insert($buffer);
                $n += count($buffer);
                $buffer = [];
            }
        }
        if ($buffer) {
            DB::table($table)->insert($buffer);
            $n += count($buffer);
        }
        return $n;
    }
}
