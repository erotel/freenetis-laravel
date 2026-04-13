<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ip6Address extends Model
{
    public $timestamps = false;
    protected $table = 'ip6_addresses';
    protected $fillable = ['iface_id', 'subnet_id', 'member_id', 'ip_address', 'dhcp', 'gateway', 'service'];

    public function iface()
    {
        return $this->belongsTo(Iface::class);
    }

    public function subnet()
    {
        return $this->belongsTo(Subnet::class);
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }
}
