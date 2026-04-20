<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subnet extends Model
{
    public $timestamps = false;
    protected $table = 'subnets';
    protected $fillable = [
        'name', 'network_address', 'netmask',
        'redirect', 'dhcp', 'dhcp_expired', 'dns', 'qos', 'OSPF_area_id',
    ];
    protected $casts = [
        'redirect'     => 'boolean',
        'dhcp'         => 'boolean',
        'dhcp_expired' => 'boolean',
        'dns'          => 'boolean',
        'qos'          => 'boolean',
    ];

    public function setExpired(): void
    {
        $this->update(['dhcp_expired' => 1]);
    }

    public function setNotExpired(): void
    {
        $this->update(['dhcp_expired' => 0]);
    }

    public function scopeExpired($query)
    {
        return $query->where('dhcp_expired', 1);
    }

    public function ipAddresses()
    {
        return $this->hasMany(IpAddress::class);
    }

    public function allowedSubnets()
    {
        return $this->hasMany(AllowedSubnet::class);
    }

    public function getLabelAttribute(): string
    {
        return $this->network_address . '/' . $this->netmask
            . ($this->name ? ' - ' . $this->name : '');
    }

    public function __toString(): string
    {
        return $this->label;
    }
}
