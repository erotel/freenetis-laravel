<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    public $timestamps = false;
    protected $table = 'accounts';
    // Full model will be implemented when accounts controller is migrated

    public function variableSymbols()
    {
        return $this->hasMany(VariableSymbol::class);
    }
}
