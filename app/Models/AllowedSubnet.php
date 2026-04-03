<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AllowedSubnet extends Model
{
    public $timestamps = false;
    protected $table = 'allowed_subnets';
}
