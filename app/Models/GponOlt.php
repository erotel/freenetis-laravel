<?php

namespace App\Models;

use App\Models\Concerns\EncryptsSensitiveAttributes;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class GponOlt extends Model
{
    use \App\Models\Concerns\Auditable;

    use EncryptsSensitiveAttributes;

    protected $fillable = [
        'name', 'ip', 'snmp_user', 'snmp_auth_pass', 'snmp_priv_pass',
        'snmp_auth_proto', 'snmp_priv_proto', 'line_prof', 'service_prof',
        'traffic_table', 'gpon_port', 'base_vlan', 'port_count', 'vlan_map', 'geocode_city',
    ];

    protected $casts = [
        'vlan_map'   => 'array',
        'base_vlan'  => 'integer',
        'port_count' => 'integer',
    ];

    /** SNMPv3 hesla OLT šifrujeme „at rest" (fallback na legacy plaintext). */
    protected function snmpAuthPass(): Attribute
    {
        return Attribute::make(
            get: fn($value) => self::decryptSensitive($value),
            set: fn($value) => self::encryptSensitive($value),
        );
    }

    protected function snmpPrivPass(): Attribute
    {
        return Attribute::make(
            get: fn($value) => self::decryptSensitive($value),
            set: fn($value) => self::encryptSensitive($value),
        );
    }

    public function onts()
    {
        return $this->hasMany(Ont::class, 'olt_ip', 'ip');
    }

    public function getVlan(int $portNum): int
    {
        if (!empty($this->vlan_map) && isset($this->vlan_map[(string) $portNum])) {
            return (int) $this->vlan_map[(string) $portNum];
        }
        return ($this->base_vlan ?? 200) + $portNum;
    }

    /** Frame číslo z gpon_port (např. '0/1/0' → 0) */
    public function getFrame(): int
    {
        return (int) (explode('/', $this->gpon_port)[0] ?? 0);
    }

    /** Slot číslo z gpon_port (např. '0/1/0' → 1) */
    public function getSlot(): int
    {
        return (int) (explode('/', $this->gpon_port)[1] ?? 1);
    }
}
