<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Message extends Model
{
    public $timestamps = false;
    protected $table = 'messages';
    protected $fillable = ['name', 'text', 'email_text', 'sms_text', 'type', 'self_cancel', 'ignore_whitelist'];

    // Message types
    const USER_MESSAGE                   = 0;
    const CONTACT_INFORMATION            = 1;
    const CANCEL_MESSAGE                 = 2;
    const UNKNOWN_DEVICE_MESSAGE         = 3;
    const INTERRUPTED_MEMBERSHIP_MESSAGE = 4;
    const DEBTOR_MESSAGE                 = 5;
    const PAYMENT_NOTICE_MESSAGE         = 6;
    const UNALLOWED_CONNECTING_PLACE     = 7;
    const RECEIVED_PAYMENT_NOTICE        = 8;
    const DEBTOR_MESSAGE_CLEN            = 25;
    const PAYMENT_NOTICE_MESSAGE_CLEN    = 26;
    const CONTRACT_SIGN_LINK             = 28;
    const CONTRACT_ADDON_SIGN_LINK       = 29;
    const CONTRACT_SIGNED                = 30;
    const CONTRACT_ADDON_SIGNED          = 31;
    const PENDING_CUSTOMER_MESSAGE       = 32;
    const PAYMENT_BLOCKED_MESSAGE        = 33;

    // Self-cancel options
    const SELF_CANCEL_DISABLED = 0;
    const SELF_CANCEL_MEMBER   = 1;
    const SELF_CANCEL_IP       = 2;

    public static function typeLabel(int $type): string
    {
        return match($type) {
            self::USER_MESSAGE                   => 'Uživatelská zpráva',
            self::CONTACT_INFORMATION            => 'Neúplné kontaktní údaje',
            self::CANCEL_MESSAGE                 => 'Zánik členství',
            self::UNKNOWN_DEVICE_MESSAGE         => 'Neznámé zařízení',
            self::INTERRUPTED_MEMBERSHIP_MESSAGE => 'Přerušené členství',
            self::DEBTOR_MESSAGE                 => 'Dlužník (zákazník)',
            self::PAYMENT_NOTICE_MESSAGE         => 'Nízký kredit (zákazník)',
            self::UNALLOWED_CONNECTING_PLACE     => 'Nepovolené místo připojení',
            self::RECEIVED_PAYMENT_NOTICE        => 'Přijatá platba',
            self::DEBTOR_MESSAGE_CLEN            => 'Dlužník (člen)',
            self::PAYMENT_NOTICE_MESSAGE_CLEN    => 'Nízký kredit (člen)',
            self::CONTRACT_SIGN_LINK             => 'Smlouva — odkaz pro podpis',
            self::CONTRACT_ADDON_SIGN_LINK       => 'Dodatek smlouvy — odkaz pro podpis',
            self::CONTRACT_SIGNED                => 'Smlouva — po podpisu',
            self::CONTRACT_ADDON_SIGNED          => 'Dodatek smlouvy — po podpisu',
            self::PENDING_CUSTOMER_MESSAGE       => 'Čekající zákazník — nepodepsaná smlouva',
            self::PAYMENT_BLOCKED_MESSAGE        => 'Nedostatečný kredit',
            default                              => 'Systémová zpráva (typ ' . $type . ')',
        };
    }

    public function isSystem(): bool
    {
        return $this->type !== self::USER_MESSAGE;
    }

    public function ipAddresses(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(IpAddress::class, 'messages_ip_addresses', 'message_id', 'ip_address_id')
            ->withPivot(['user_id', 'comment', 'datetime']);
    }

    /**
     * Replace {key} placeholders in $body with $vars[key]. Missing keys are left as-is.
     *
     * $body je admin-psaný HTML šablona; $vars hodnoty pocházejí z user inputu
     * (member_name z public registrace, atd.) a defaultně se HTML-escape, aby
     * nemohly injektovat tagy nebo atributové XSS payloady do výsledného HTML.
     * Pro plain-text kontext (SMS) předej $htmlEscape=false.
     */
    public static function substitute(string $body, array $vars, bool $htmlEscape = true): string
    {
        if (empty($vars)) return $body;
        $search  = array_map(fn($k) => '{' . $k . '}', array_keys($vars));
        $replace = array_map(
            fn($v) => $htmlEscape
                ? htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                : (string) $v,
            array_values($vars)
        );
        return str_replace($search, $replace, $body);
    }

    /**
     * Build the standard placeholder map for a member (name, id, dates, balance).
     * $extra wins over auto-loaded values.
     */
    public static function buildPlaceholders(int $memberId, array $extra = []): array
    {
        $member = DB::table('members')->where('id', $memberId)->first();
        if (!$member) return $extra;

        $account = DB::table('accounts')
            ->where('member_id', $memberId)
            ->where('account_attribute_id', 221100)
            ->first(['id', 'balance']);

        // VS visí na účtu přes variable_symbols (jako v Kohaně) — typicky 1 VS,
        // GROUP_CONCAT pro případ víc symbolů na účet.
        $variableSymbol = '';
        if ($account) {
            $variableSymbol = DB::table('variable_symbols')
                ->where('account_id', $account->id)
                ->orderBy('id')
                ->pluck('variable_symbol')
                ->implode(',');
        }

        return array_merge([
            'member_name'     => $member->name ?? '',
            'member_id'       => $member->id,
            'leaving_date'    => $member->leaving_date ?? '',
            'entrance_date'   => $member->entrance_date ?? '',
            'balance'         => number_format((float) ($account->balance ?? 0), 2, ',', ' '),
            'variable_symbol' => $variableSymbol,
        ], $extra);
    }
}
