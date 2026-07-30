<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractParty extends Model
{
    protected $connection = 'contracts';
    protected $table = 'contract_parties';

    protected $fillable = [
        'contract_id',
        'full_name',
        'street',
        'town',
        'service_street',
        'service_town',
        'service_zip',
        'service_full_address',
        'country',
        'ico',
        'dic',
        'speed_name',
        'variable_symbol',
        'price',
        'price_after_discount',
        'discount_until',
        'phone',
        'email',
        'birthday',
    ];

    protected $casts = [
        'created_at'           => 'datetime',
        'birthday'             => 'date',
        'price'                => 'decimal:2',
        'price_after_discount' => 'decimal:2',
        'discount_until'       => 'date',
        'active'               => 'boolean',
    ];

    public $timestamps = false;

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }
}
