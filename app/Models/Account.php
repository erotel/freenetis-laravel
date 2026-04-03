<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    const ACCOUNTING_SYSTEM = 1;
    const CREDIT            = 2;
    const PROJECT           = 3;
    const OTHER             = 4;

    public $timestamps = false;
    protected $table = 'accounts';
    protected $fillable = ['member_id', 'name', 'account_attribute_id', 'balance', 'comment'];
    protected $casts = ['balance' => 'float'];

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function accountAttribute()
    {
        return $this->belongsTo(AccountAttribute::class);
    }

    public function transfers()
    {
        return $this->hasMany(Transfer::class);
    }

    public function variableSymbols()
    {
        return $this->hasMany(VariableSymbol::class);
    }

    public function __toString(): string
    {
        return $this->name ?? ('Účet #' . $this->id);
    }
}
