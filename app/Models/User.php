<?php

namespace App\Models;

use App\Models\Concerns\EncryptsSensitiveAttributes;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class User extends Authenticatable
{
    use \App\Models\Concerns\Auditable;
    use EncryptsSensitiveAttributes;

    protected $table = 'users';
    public $timestamps = false;

    protected $fillable = [
        'login', 'password', 'type', 'member_id',
        'name', 'middle_name', 'surname', 'pre_title', 'post_title',
        'birthday', 'comment', 'application_password', 'settings',
    ];
    protected $hidden = ['password', 'application_password'];

    const MAIN_USER = 1;
    const USER = 2;

    /**
     * Bezpečně vygeneruje aplikační heslo. Dřív `str_shuffle` (není CSPRNG,
     * jen 8 znaků) — teď Str::random (CSPRNG přes random_bytes), 12 znaků
     * base62 (~71 bitů entropie). Sloupec je varchar(50), takže se vejde.
     */
    public static function generateApplicationPassword(): string
    {
        return \Illuminate\Support\Str::random(12);
    }

    /**
     * application_password je šifrované at-rest (Laravel Crypt). Čtení dešifruje
     * (fallback na legacy plaintext), zápis přes Eloquent šifruje. POZOR: zápisy
     * přes DB::table() mutator NEspustí — tam se šifruje explicitně (Crypt).
     */
    protected function applicationPassword(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => self::decryptSensitive($value),
            set: fn ($value) => self::encryptSensitive($value),
        );
    }

    protected function casts(): array
    {
        return [];
    }

    // --- Authenticatable contract ---

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->id;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return $this->password;
    }

    // No remember_token column in schema — stub it out
    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void {}

    public function getRememberTokenName(): string
    {
        return '';
    }

    // --- Accessors ---

    public function getFullNameAttribute(): string
    {
        return trim(
            ($this->pre_title ? $this->pre_title . ' ' : '') .
            $this->name . ' ' .
            ($this->middle_name ? $this->middle_name . ' ' : '') .
            $this->surname .
            ($this->post_title ? ', ' . $this->post_title : '')
        );
    }

    // --- Relations ---

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function devices()
    {
        return $this->hasMany(\App\Models\Device::class);
    }

    public function logs()
    {
        return $this->hasMany(\App\Models\Log::class);
    }

    public function usersKeys()
    {
        return $this->hasMany(\App\Models\UserKey::class);
    }

    public function contacts()
    {
        return $this->belongsToMany(Contact::class, 'users_contacts', 'user_id', 'contact_id')
                    ->withPivot('mail_redirection');
    }
}
