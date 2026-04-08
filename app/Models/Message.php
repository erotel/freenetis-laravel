<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
}
