<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OutgoingPayment extends Model
{
    protected $table = 'outgoing_payments';

    const STATUS_DRAFT     = 'draft';
    const STATUS_APPROVED  = 'approved';
    const STATUS_EXPORTED  = 'exported';
    const STATUS_PAID      = 'paid';
    const STATUS_CANCELLED = 'cancelled';

    const REASON_UNIDENTIFIED = 'unidentified_refund';
    const REASON_TERMINATION  = 'termination_refund';
    const REASON_OTHER        = 'other';

    protected $fillable = [
        'bank_account_id', 'transfer_id', 'target_account', 'target_name',
        'amount', 'currency', 'variable_symbol', 'message', 'reason',
        'status', 'created_by', 'approved_by',
        'exported_at', 'export_ref', 'export_filename', 'api_response',
        'paid_at', 'paid_transfer_id', 'match_method',
    ];

    public static function statusLabels(): array
    {
        return [
            self::STATUS_DRAFT     => 'Koncept',
            self::STATUS_APPROVED  => 'Schváleno',
            self::STATUS_EXPORTED  => 'Exportováno',
            self::STATUS_PAID      => 'Zaplaceno',
            self::STATUS_CANCELLED => 'Zrušeno',
        ];
    }

    public static function reasonLabels(): array
    {
        return [
            self::REASON_UNIDENTIFIED => 'Vrácení neident. platby',
            self::REASON_TERMINATION  => 'Vrácení při ukončení',
            self::REASON_OTHER        => 'Jiné',
        ];
    }

    public static function statusColors(): array
    {
        return [
            self::STATUS_DRAFT     => '#888',
            self::STATUS_APPROVED  => '#0a0',
            self::STATUS_EXPORTED  => '#00a',
            self::STATUS_PAID      => '#070',
            self::STATUS_CANCELLED => '#c00',
        ];
    }

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function transfer()
    {
        return $this->belongsTo(Transfer::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
