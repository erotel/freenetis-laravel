<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VariableSymbol extends Model
{
    public $timestamps = false;
    protected $table = 'variable_symbols';

    public function account()
    {
        return $this->belongsTo(Account::class);
    }
}
