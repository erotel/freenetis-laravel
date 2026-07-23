<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractEvent extends Model
{
    protected $connection = 'contracts';
    protected $table = 'contract_events';

    protected $fillable = [
        'contract_id',
        'event',
        'meta_json',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public $timestamps = false;

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    public function meta(): array
    {
        return $this->meta_json ? (json_decode($this->meta_json, true) ?? []) : [];
    }

    public function eventLabel(): string
    {
        return match($this->event) {
            'created'      => 'Smlouva vytvořena',
            'otp_sent'     => 'OTP odesláno',
            'otp_attempt'  => 'Pokus o ověření OTP',
            'otp_verified' => 'OTP ověřeno',
            'signed'       => 'Podepsáno',
            'link_issued'  => 'Odkaz odeslán',
            'canceled'     => 'Zrušeno',
            'terminated'   => 'Ukončeno (výpověď)',
            'party_refreshed'        => 'Údaje aktualizovány ze člena',
            'service_address_edited' => 'Upraveno místo připojení',
            default        => $this->event,
        };
    }
}
