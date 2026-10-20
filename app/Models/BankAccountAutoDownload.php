<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankAccountAutoDownload extends Model
{
    use \App\Models\Concerns\Auditable;

    public $timestamps = false;
    protected $table = 'bank_accounts_automatical_downloads';

    protected $fillable = ['bank_account_id', 'type', 'attribute', 'email_enabled', 'sms_enabled'];

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }
}
