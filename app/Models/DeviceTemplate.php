<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceTemplate extends Model
{
    public $timestamps = false;
    protected $table = 'device_templates';
    protected $casts = ['values' => 'array', 'default' => 'boolean'];

    public function enumType()
    {
        return $this->belongsTo(EnumType::class, 'enum_type_id');
    }

    public function getIfaceDefinitions(): array
    {
        $defs = [];
        foreach ($this->values ?? [] as $key => $val) {
            if ($key === 'default_iface') {
                continue;
            }
            $type = (int) ($val['type'] ?? $key);
            // Wireless uses min_count/max_count; others use count
            // Detect by presence of min_count key (robust against type numbering differences)
            $isWireless = array_key_exists('min_count', $val);
            $count     = $isWireless
                ? (int) ($val['max_count'] ?? 0)
                : (int) ($val['count'] ?? 0);
            $minCount  = $isWireless ? (int) ($val['min_count'] ?? 0) : $count;
            $maxCount  = $isWireless ? (int) ($val['max_count'] ?? 0) : $count;

            $defs[] = [
                'key'        => $key,
                'type'       => $type,
                'type_label' => Iface::typeLabels()[$type] ?? '?',
                'count'      => $count,
                'min_count'  => $minCount,
                'max_count'  => $maxCount,
                'has_ip'     => (bool) ($val['has_ip'] ?? false),
                'has_mac'    => (bool) ($val['has_mac'] ?? false),
                'has_link'   => (bool) ($val['has_link'] ?? false),
                'items'      => $val['items'] ?? [],
            ];
        }
        return $defs;
    }
}
