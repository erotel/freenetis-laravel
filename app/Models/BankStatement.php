<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankStatement extends Model
{
    public $timestamps = false;
    protected $table = 'bank_statements';

    protected $casts = [
        'from' => 'datetime',
        'to'   => 'datetime',
    ];

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function bankTransfers()
    {
        return $this->hasMany(BankTransfer::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
