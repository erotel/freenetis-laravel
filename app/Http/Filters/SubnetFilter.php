<?php

namespace App\Http\Filters;

class SubnetFilter
{
    public static function fields(): array
    {
        return [
            ['key' => 'name',            'label' => 'Název',           'type' => 'text'],
            ['key' => 'network_address',  'label' => 'Síťová adresa',   'type' => 'text'],
            ['key' => 'vlan',            'label' => 'VLAN',            'type' => 'text'],
            ['key' => 'dhcp',            'label' => 'DHCP',            'type' => 'select', 'values' => ['' => '— vše —', '1' => 'Ano', '0' => 'Ne']],
        ];
    }

    public static function apply($query, array $filters): void
    {
        foreach ($filters as $filter) {
            if (empty($filter['active'])) continue;
            $field = $filter['field'] ?? '';
            $op    = $filter['op']    ?? 'contains';
            $value = $filter['value'] ?? '';
            if ($field === '') continue;

            switch ($field) {
                case 'name':
                    self::text($query, 'subnets.name', $op, $value);
                    break;
                case 'network_address':
                    self::text($query, 'subnets.network_address', $op, $value);
                    break;
                case 'vlan':
                    $query->whereHas('vlan', fn($q) => self::text($q, 'vlans.name', $op, $value));
                    break;
                case 'dhcp':
                    if ($value !== '') {
                        $query->where('subnets.dhcp', (int) $value);
                    }
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
