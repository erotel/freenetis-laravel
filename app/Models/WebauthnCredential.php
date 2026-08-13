<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebauthnCredential extends Model
{
    use \App\Models\Concerns\Auditable;

    public $timestamps = false;
    protected $table = 'webauthn_credentials';

    protected $fillable = [
        'user_id', 'credential_id', 'public_key',
        'sign_count', 'device_name', 'created_at', 'last_used_at',
    ];

    protected $casts = [
        'sign_count'   => 'integer',
        'created_at'   => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
