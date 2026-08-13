<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * TOTP konfigurace uživatele (dvoufázové přihlášení). Jeden řádek na uživatele.
 * `totp_secret` je šifrovaný at-rest (cast 'encrypted') a v audit trailu
 * redigovaný na '***' (auditRedact).
 */
class UserMfa extends Model
{
    use \App\Models\Concerns\Auditable;

    protected $table = 'user_mfa';

    protected $guarded = [];

    protected $casts = [
        'totp_secret'  => 'encrypted',
        'confirmed_at' => 'datetime',
    ];

    /** Pole, která audit trail nikdy nezapíše v plaintextu. */
    protected array $auditRedact = ['totp_secret'];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
