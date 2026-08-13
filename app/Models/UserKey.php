<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserKey extends Model
{
    use \App\Models\Concerns\Auditable;

    protected $table = 'users_keys';
    public $timestamps = false;
}
