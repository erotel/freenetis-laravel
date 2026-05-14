<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transfer extends Model
{
    const DEDUCT_MEMBER_FEE       = 1;
    const DEDUCT_ENTRANCE_FEE     = 2;
    const DEDUCT_VOIP_UNACCOUNTED = 3;
    const DEDUCT_VOIP_ACCOUNTED   = 4;
    const DEDUCT_DEVICE_FEE       = 5;

    public $timestamps = false;
    protected $table = 'transfers';
    protected $fillable = [
        'origin_id', 'destination_id', 'previous_transfer_id',
        'member_id', 'user_id', 'type', 'datetime',
        'creation_datetime', 'text', 'amount',
    ];
    protected $casts = [
        'amount'            => 'float',
        'type'              => 'integer',
        'datetime'          => 'datetime',
        'creation_datetime' => 'datetime',
    ];

    public static function typeLabels(): array
    {
        return [
            self::DEDUCT_MEMBER_FEE       => 'Členský příspěvek',
            self::DEDUCT_ENTRANCE_FEE     => 'Vstupní příspěvek',
            self::DEDUCT_VOIP_UNACCOUNTED => 'VoIP (nezaúčtovaný)',
            self::DEDUCT_VOIP_ACCOUNTED   => 'VoIP (zaúčtovaný)',
            self::DEDUCT_DEVICE_FEE       => 'Splátka zařízení',
        ];
    }

    public static function typeLabel(int $type): string
    {
        return self::typeLabels()[$type] ?? 'Převod';
    }

    public function origin()
    {
        return $this->belongsTo(Account::class, 'origin_id');
    }

    public function destination()
    {
        return $this->belongsTo(Account::class, 'destination_id');
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function previousTransfer()
    {
        return $this->belongsTo(Transfer::class, 'previous_transfer_id');
    }

    /**
     * Transfers that point back to this one — typically returns (vratky), opravy
     * nebo storna, které referencují tento převod jako svůj `previous_transfer_id`.
     */
    public function dependentTransfers()
    {
        return $this->hasMany(Transfer::class, 'previous_transfer_id');
    }

    /**
     * Bankovní převod (FIO/import), pokud byl tento účetní převod vytvořen
     * spárováním s bank statementem. Skrz bank_transfers.transfer_id.
     */
    public function bankTransfer()
    {
        return $this->hasOne(BankTransfer::class, 'transfer_id');
    }

    /**
     * Signed amount from the perspective of a given account:
     * positive = inbound (account is destination), negative = outbound (account is origin).
     */
    public function signedAmount(int $accountId): float
    {
        return $this->destination_id === $accountId ? $this->amount : -$this->amount;
    }
}
