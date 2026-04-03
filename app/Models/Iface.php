<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Iface extends Model
{
    const ETHERNET   = 1;
    const WIRELESS   = 2;
    const BRIDGE     = 3;
    const PORT       = 4;
    const INTERNAL   = 5;
    const VLAN_IFACE = 6;
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

    public function vlans()
    {
        return $this->belongsToMany(Vlan::class, 'ifaces_vlans');
    }

    public function getTypeLabelAttribute(): string
    {
        return self::typeLabels()[$this->type] ?? 'Neznámý';
    }
}
