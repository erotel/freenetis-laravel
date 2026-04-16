<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Iface extends Model
{
    // Matches Kohana Iface_Model constants (stored in DB)
    const WIRELESS   = 1;
    const ETHERNET   = 2;
    const PORT       = 3;
    const BRIDGE     = 4;
    const VLAN_IFACE = 5;
    const INTERNAL   = 6;
    const VIRTUAL_AP = 7;

    public static function typeLabels(): array
    {
        return [
            self::ETHERNET   => 'Ethernet',
            self::WIRELESS   => 'Wireless',
            self::BRIDGE     => 'Bridge',
            self::PORT       => 'Port',
            self::INTERNAL   => 'Internal',
            self::VLAN_IFACE => 'VLAN',
            self::VIRTUAL_AP => 'Virtual AP',
        ];
    }

    public $timestamps = false;
    protected $table = 'ifaces';
    protected $fillable = [
        'type', 'device_id', 'link_id', 'mac', 'name', 'number',
        'wireless_mode', 'wireless_antenna', 'port_mode', 'comment',
    ];
    protected $casts = ['type' => 'integer'];

    public function device()
    {
        return $this->belongsTo(Device::class);
    }

    public function ipAddresses()
    {
        return $this->hasMany(IpAddress::class);
    }

    public function ip6Addresses()
    {
        return $this->hasMany(Ip6Address::class);
    }

    public function vlans()
    {
        return $this->belongsToMany(Vlan::class, 'ifaces_vlans');
    }

    public function getTypeLabelAttribute(): string
    {
        return self::typeLabels()[$this->type] ?? 'Neznámý';
    }
}
