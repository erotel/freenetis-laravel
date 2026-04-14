<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankAccountAutoDownload extends Model
{
    public $timestamps = false;
    protected $table = 'bank_accounts_automatical_downloads';

    protected $fillable = ['bank_account_id', 'type', 'attribute', 'email_enabled', 'sms_enabled'];

    // Mirrors Time_Activity_Rule constants
    const TYPE_MONTHLY         = 1;
    const TYPE_WEEKLY          = 2;
    const TYPE_DAILY           = 3;
    const TYPE_DAILY_WD        = 4;
    const TYPE_HOURLY          = 5;
    const TYPE_AFTER_DEDUCTION = 6;

    public static function typeLabels(): array
    {
        return [
            self::TYPE_MONTHLY         => 'Měsíčně',
            self::TYPE_WEEKLY          => 'Týdně',
            self::TYPE_DAILY           => 'Denně',
            self::TYPE_DAILY_WD        => 'Denně (pracovní dny)',
            self::TYPE_HOURLY          => 'Každou hodinu',
            self::TYPE_AFTER_DEDUCTION => 'Po srážce poplatků',
        ];
    }

    /**
     * Attribute definitions per type (mirrors Time_Activity_Rule::$type_attributes).
     * Each entry: ['name' => label, 'min' => int, 'max' => int]
     */
    public static function typeAttributes(): array
    {
        return [
            self::TYPE_MONTHLY         => [
                ['name' => 'Den v měsíci', 'min' => 1,  'max' => 31],
                ['name' => 'Hodina',       'min' => 0,  'max' => 23],
            ],
            self::TYPE_WEEKLY          => [
                ['name' => 'Den týdne (1=Po, 7=Ne)', 'min' => 1, 'max' => 7],
                ['name' => 'Hodina',                  'min' => 0, 'max' => 23],
            ],
            self::TYPE_DAILY           => [
                ['name' => 'Hodina', 'min' => 0, 'max' => 23],
            ],
            self::TYPE_DAILY_WD        => [
                ['name' => 'Hodina', 'min' => 0, 'max' => 23],
            ],
            self::TYPE_HOURLY          => [],  // no attributes
            self::TYPE_AFTER_DEDUCTION => [
                ['name' => 'Hodina', 'min' => 0, 'max' => 23],
            ],
        ];
    }

    public function typeLabel(): string
    {
        return self::typeLabels()[$this->type] ?? 'Neznámý';
    }

    /**
     * Return human-readable description of the attribute value.
     */
    public function attributeLabel(): string
    {
        $attrs = $this->attribute !== null ? explode('/', $this->attribute) : [];
        $defs  = self::typeAttributes()[$this->type] ?? [];

        if (empty($defs)) {
            return '—';
        }

        $parts = [];
        foreach ($defs as $i => $def) {
            $val = $attrs[$i] ?? '?';
            $parts[] = $def['name'] . ': ' . $val;
        }
        return implode(', ', $parts);
    }

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }
}
