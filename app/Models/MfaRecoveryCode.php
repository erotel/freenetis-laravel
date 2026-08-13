<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Jednorázový záložní kód pro přístup při ztrátě telefonu. Ukládá se JEN hash;
 * plaintext se ukáže uživateli jednou při vygenerování.
 */
class MfaRecoveryCode extends Model
{
    protected $table = 'mfa_recovery_codes';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'used_at'    => 'datetime',
        'created_at' => 'datetime',
    ];
}
