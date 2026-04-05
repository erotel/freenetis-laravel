<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VariableSymbol extends Model
{
    public $timestamps = false;
    protected $table = 'variable_symbols';
    protected $fillable = ['account_id', 'variable_symbol'];

    public function account()
    {
        return $this->belongsTo(Account::class);
    }
}
