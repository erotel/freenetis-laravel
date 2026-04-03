<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoginLog extends Model
{
    public $timestamps = false;
    protected $table = 'login_logs';
    protected $fillable = ['user_id', 'time', 'IP_address'];
    protected $casts = ['time' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
