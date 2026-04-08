<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageAutoSetting extends Model
{
    public $timestamps = false;
    protected $table   = 'messages_automatical_activations';
    protected $fillable = [
        'message_id', 'type', 'attribute',
        'redirection_enabled', 'email_enabled', 'sms_enabled',
        'send_activation_to_email',
    ];

    const TYPE_MONTHLY         = 1;
    const TYPE_WEEKLY          = 2;
    const TYPE_DAILY           = 3;
    const TYPE_DAILY_WD        = 4;
    const TYPE_HOURLY          = 5;
    const TYPE_AFTER_DEDUCTION = 6;

    public static function typeLabel(int $type): string
    {
        return match($type) {
            self::TYPE_MONTHLY         => 'měsíčně',
            self::TYPE_WEEKLY          => 'týdně',
            self::TYPE_DAILY           => 'denně',
            self::TYPE_DAILY_WD        => 'denně (prac. dny)',
            self::TYPE_HOURLY          => 'každou hodinu',
            self::TYPE_AFTER_DEDUCTION => 'v den stržení',
            default => 'neznámý',
        };
    }

    public function message(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public function attributeLabel(): string
    {
        $attr = trim($this->attribute ?? '');
        if (!$attr) return '—';

        $parts = explode('/', $attr);

        return match($this->type) {
            self::TYPE_MONTHLY,
            self::TYPE_AFTER_DEDUCTION =>
                implode(', ', array_filter([
                    isset($parts[0]) && $parts[0] !== '' ? $parts[0] . ' (den v měsíci)' : null,
                    isset($parts[1]) && $parts[1] !== '' ? $parts[1] . ' (hodina)'        : null,
                ])),
            self::TYPE_WEEKLY =>
                implode(', ', array_filter([
                    isset($parts[0]) && $parts[0] !== '' ? $parts[0] . ' (den týdne)' : null,
                    isset($parts[1]) && $parts[1] !== '' ? $parts[1] . ' (hodina)'    : null,
                ])),
            self::TYPE_DAILY,
            self::TYPE_DAILY_WD =>
                ltrim($attr, '/') . ' (hodina)',
            default => $attr,
        };
    }
}
