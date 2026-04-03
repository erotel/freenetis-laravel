<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceAdmin extends Model
{
    public $timestamps = false;
    protected $table = 'device_admins';

    public function device()
    {
        return $this->belongsTo(Device::class);
    }
}
