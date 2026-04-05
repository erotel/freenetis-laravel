<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankAccount extends Model
{
    public $timestamps = false;
    protected $table = 'bank_accounts';
    protected $fillable = ['name', 'member_id', 'account_nr', 'bank_nr', 'type', 'IBAN', 'SWIFT', 'payment_purpose'];

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function bankStatements()
    {
        return $this->hasMany(BankStatement::class);
    }

    public function bankTransfers()
    {
        return $this->hasManyThrough(BankTransfer::class, BankStatement::class);
    }

    public function outgoingPayments()
    {
        return $this->hasMany(OutgoingPayment::class);
    }

    public function getFullAccountNumberAttribute(): string
    {
        return ($this->account_nr ?? '') . '/' . ($this->bank_nr ?? '');
    }
}
