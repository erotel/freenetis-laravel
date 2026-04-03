<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Iface extends Model
{
    public $timestamps = false;
    protected $table = 'ifaces';

    public function device()
    {
        return $this->belongsTo(Device::class);
    }

    public function ipAddresses()
    {
        return $this->hasMany(IpAddress::class);
    }
}
