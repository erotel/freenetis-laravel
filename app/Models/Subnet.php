<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subnet extends Model
{
    use \App\Models\Concerns\Auditable;

    public $timestamps = false;
    protected $table = 'subnets';
    // Zachovej mikrosekundy při zápisu dhcp_changed_at (výchozí 'Y-m-d H:i:s' by
    // je ořízl na vteřiny → kolize se stažením klienta ve stejné vteřině). Subnet
    // nemá jiný datetime sloupec, takže tenhle formát nic dalšího neovlivní.
    protected $dateFormat = 'Y-m-d H:i:s.u';
    protected $fillable = [
        'name', 'network_address', 'netmask',
        'redirect', 'dhcp', 'dhcp_expired', 'dhcp_changed_at', 'dns', 'qos', 'OSPF_area_id',
    ];
    protected $casts = [
        'redirect'        => 'boolean',
        'dhcp'            => 'boolean',
        'dhcp_expired'    => 'boolean',
        'dhcp_changed_at' => 'datetime',
        'dns'             => 'boolean',
        'qos'             => 'boolean',
    ];

    public function setExpired(): void
    {
        // dhcp_expired = sdílený flag pro legacy jednoho-konzumenta;
        // dhcp_changed_at = timestamp pro per-client detekci (více DHCP serverů).
        $this->update(['dhcp_expired' => 1, 'dhcp_changed_at' => now()]);
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
