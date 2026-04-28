<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contract extends Model
{
    protected $connection = 'contracts';
    protected $table = 'contracts';

    protected $fillable = [
        'member_id',
        'contract_no',
        'status',
        'phone',
        'signed_at',
    ];

    protected $casts = [
        'signed_at'      => 'datetime',
        'addon_signed_at' => 'datetime',
        'created_at'     => 'datetime',
        'addon'          => 'boolean',
        'addon_signed'   => 'boolean',
    ];

    public $timestamps = false;

    public function parties(): HasMany
    {
        return $this->hasMany(ContractParty::class, 'contract_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ContractEvent::class, 'contract_id')->orderBy('created_at');
    }

    public function statusLabel(): string
    {
        return match($this->status) {
            'draft'        => 'Návrh',
            'otp_sent'     => 'Čeká na podpis',
            'otp_verified' => 'Ověřeno',
            'signed'       => 'Podepsáno',
            'canceled'     => 'Zrušeno',
            default        => $this->status,
        };
    }

    public function statusColor(): string
    {
        return match($this->status) {
            'draft'        => 'amber',
            'otp_sent'     => 'amber',
            'otp_verified' => 'blue',
            'signed'       => 'green',
            'canceled'     => 'red',
            default        => 'gray',
        };
    }
}
