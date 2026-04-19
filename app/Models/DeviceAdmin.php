<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceAdmin extends Model
{
    public $timestamps = false;
    protected $table = 'device_admins';
    protected $fillable = ['device_id', 'user_id'];

    public function device()
    {
        return $this->belongsTo(Device::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
