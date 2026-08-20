<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BankTransfer extends Model
{
    use \App\Models\Concerns\Auditable;
    // bank_transfers vznikají hromadně automatickým importem výpisů (systém) —
    // ty se neaudituji per-řádek (import má souhrnný audit); lidský zásah ano.
    use \App\Models\Concerns\AuditsHumanActionsOnly;

    use SoftDeletes;

    public $timestamps = false;
    protected $table = 'bank_transfers';
    protected $dates = ['deleted_at'];

    public function bankStatement()
    {
        return $this->belongsTo(BankStatement::class);
    }

    public function transfer()
    {
        return $this->belongsTo(Transfer::class);
    }

    public function originAccount()
    {
        return $this->belongsTo(BankAccount::class, 'origin_id');
    }

    public function destinationAccount()
    {
        return $this->belongsTo(BankAccount::class, 'destination_id');
    }
}
