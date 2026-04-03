<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceEngineer extends Model
{
    public $timestamps = false;
    protected $table = 'device_engineers';

    public function device()
    {
        return $this->belongsTo(Device::class);
    }
}
