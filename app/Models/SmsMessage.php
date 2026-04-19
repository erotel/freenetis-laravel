<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsMessage extends Model
{
    public $timestamps = false;
    protected $table = 'sms_messages';
    protected $fillable = [
        'user_id', 'sms_message_id', 'stamp', 'send_date',
        'text', 'sender', 'receiver', 'driver', 'type', 'state', 'message',
    ];

    // type
    const TYPE_RECEIVED = 0;
    const TYPE_SENT     = 1;

    // state (depends on type)
    const STATE_RECEIVED_UNREAD = 0;
    const STATE_RECEIVED_READ   = 1;
    const STATE_SENT_OK         = 0;
    const STATE_SENT_UNSENT     = 1;
    const STATE_SENT_FAILED     = 2;

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function typeName(): string
    {
        return match ((int) $this->type) {
            self::TYPE_RECEIVED => 'Přijatá',
            self::TYPE_SENT     => 'Odeslaná',
            default             => (string) $this->type,
        };
    }

    public function stateName(): string
    {
        if ((int) $this->type === self::TYPE_RECEIVED) {
            return match ((int) $this->state) {
                self::STATE_RECEIVED_UNREAD => 'Nepřečtená',
                self::STATE_RECEIVED_READ   => 'Přečtená',
                default                     => (string) $this->state,
            };
        }
        return match ((int) $this->state) {
            self::STATE_SENT_OK     => 'Odeslaná',
            self::STATE_SENT_UNSENT => 'Čekající',
            self::STATE_SENT_FAILED => 'Chyba',
            default                 => (string) $this->state,
        };
    }
}
