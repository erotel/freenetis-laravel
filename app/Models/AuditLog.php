<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Jeden záznam audit trailu (kdo/co/kdy). Zápis dělá {@see \App\Services\AuditLogger},
 * tento model slouží hlavně pro čtení (historie změn objektu).
 *
 * Tabulka nemá PRIMARY KEY (kvůli partitioningu), ale `id` je AUTO_INCREMENT,
 * takže Eloquent s ním pracuje standardně.
 */
class AuditLog extends Model
{
    protected $table = 'audit_logs';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'occurred_at' => 'datetime',
        'old_values'  => 'array',
        'new_values'  => 'array',
    ];

    /** Kdo změnu provedl (null = systém/cron). */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
