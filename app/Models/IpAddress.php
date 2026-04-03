<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IpAddress extends Model
{
    public $timestamps = false;
    protected $table = 'ip_addresses';
    protected $fillable = [
        'iface_id', 'subnet_id', 'member_id',
        'ip_address', 'dhcp', 'gateway', 'service',
    ];
    protected $casts = [
        'dhcp'    => 'boolean',
        'gateway' => 'boolean',
        'service' => 'boolean',
    ];

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

    public function __toString(): string
    {
        return $this->ip_address ?? '';
    }
}
