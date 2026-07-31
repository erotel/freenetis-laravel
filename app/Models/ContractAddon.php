<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractAddon extends Model
{
    protected $connection = 'contracts';
    protected $table = 'contract_addons';
    public $timestamps = false;

    protected $fillable = [
        'contract_id',
        'type',
        'addon_no',
        'old_speed_name',
        'old_price',
        'old_price_after_discount',
        'new_speed_name',
        'new_price',
        'new_price_after_discount',
        'discount_until',
        'service_name',
        'service_price',
        'service_action',
        'effective_date',
        'status',
        'pdf_path',
        'signed_at',
        'created_by',
        'created_at',
    ];

    protected $casts = [
        'addon_no'                 => 'integer',
        'old_price'                => 'decimal:2',
        'old_price_after_discount' => 'decimal:2',
        'new_price'                => 'decimal:2',
        'new_price_after_discount' => 'decimal:2',
        'discount_until'           => 'date',
        'service_price'            => 'decimal:2',
        'effective_date'           => 'date',
        'signed_at'                => 'datetime',
        'created_at'               => 'datetime',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    /** "400 Kč" / "—" (NULL). Tisíce mezerou, bez zbytečných desetin. */
    public function priceLabel($value): string
    {
        return $value === null || $value === ''
            ? '—'
            : rtrim(rtrim(number_format((float) $value, 2, ',', ' '), '0'), ',') . ' Kč';
    }

    /** "Přidání" / "Zrušení" služby — jen pro type='additional_service'. */
    public function serviceActionLabel(): string
    {
        return match ($this->service_action) {
            'add'    => 'Přidání',
            'remove' => 'Zrušení',
            default  => '—',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'draft'    => 'Návrh',
            'otp_sent' => 'Čeká na podpis',
            'signed'   => 'Podepsáno',
            'canceled' => 'Zrušeno',
            default    => $this->status,
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'draft'    => 'amber',
            'otp_sent' => 'amber',
            'signed'   => 'green',
            'canceled' => 'red',
            default    => 'gray',
        };
    }
}
