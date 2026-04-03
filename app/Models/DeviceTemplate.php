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
            $defs[] = [
                'key'        => $key,
                'type'       => $val['type'],
                'type_label' => Iface::typeLabels()[$val['type']] ?? '?',
                'count'      => $val['count'] ?? 1,
                'has_ip'     => $val['has_ip'] ?? false,
                'has_mac'    => $val['has_mac'] ?? false,
                'items'      => $val['items'] ?? [],
            ];
        }
        return $defs;
    }
}
