<?php

namespace App\Http\Filters;

use Illuminate\Support\Facades\DB;

class DeviceFilter
{
    public static function fields(): array
    {
        $types = DB::table('enum_types')
            ->whereIn('id', DB::table('devices')->distinct()->pluck('type'))
            ->orderBy('value')
            ->pluck('value', 'id')
            ->toArray();

        return [
            ['key' => 'name',       'label' => 'Název',         'type' => 'text'],
            ['key' => 'type',       'label' => 'Typ',           'type' => 'select', 'values' => ['' => '— vše —'] + $types],
            ['key' => 'user_login', 'label' => 'Login uživatele','type' => 'text'],
            ['key' => 'ip_address', 'label' => 'IP adresa',     'type' => 'text'],
            ['key' => 'mac',        'label' => 'MAC adresa',    'type' => 'text'],
        ];
    }

    public static function apply($query, array $filters): void
    {
        foreach ($filters as $filter) {
            if (empty($filter['active'])) continue;
            $field = $filter['field'] ?? '';
            $op    = $filter['op']    ?? 'contains';
            $value = $filter['value'] ?? '';
            if ($field === '' || $value === '') continue;

            switch ($field) {
                case 'name':
                    self::text($query, 'devices.name', $op, $value);
                    break;
                case 'type':
                    $query->where('devices.type', $value);
                    break;
                case 'user_login':
                    $query->whereHas('user', fn($q) => self::text($q, 'users.login', $op, $value));
                    break;
                case 'ip_address':
                    $query->whereHas('ifaces.ipAddresses', fn($q) => self::text($q, 'ip_addresses.ip_address', $op, $value));
                    break;
                case 'mac':
                    $query->whereHas('ifaces', fn($q) => self::text($q, 'ifaces.mac', $op, $value));
                    break;
            }
        }
    }

    private static function text($query, string $col, string $op, $value): void
    {
        match ($op) {
            'contains'     => $query->where($col, 'like', "%{$value}%"),
            'not_contains' => $query->where($col, 'not like', "%{$value}%"),
            'eq'           => $query->where($col, '=', $value),
            'neq'          => $query->where($col, '!=', $value),
            'empty'        => $query->where(fn($q) => $q->whereNull($col)->orWhere($col, '')),
            'not_empty'    => $query->whereNotNull($col)->where($col, '!=', ''),
            default        => $query->where($col, 'like', "%{$value}%"),
        };
    }
}
