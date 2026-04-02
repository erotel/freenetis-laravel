<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class User extends Authenticatable
{
    protected $table = 'users';
    public $timestamps = false;

    protected $fillable = ['login', 'password', 'type', 'member_id'];
    protected $hidden = ['password'];

    const MAIN_USER = 1;
    const USER = 2;

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

    // --- Relations ---

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
