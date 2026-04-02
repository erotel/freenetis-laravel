<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Member extends Model
{
    protected $table = 'members';
    public $timestamps = false;

    protected $fillable = ['name', 'locked', 'type'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
