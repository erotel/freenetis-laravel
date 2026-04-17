<?php

namespace App\Http\Filters;

class TransferFilter
{
    public static function fields(): array
    {
        return [
            ['key' => 'description',  'label' => 'Popis',        'type' => 'text'],
            ['key' => 'amount_from',  'label' => 'Částka od',    'type' => 'number'],
            ['key' => 'amount_to',    'label' => 'Částka do',    'type' => 'number'],
            ['key' => 'date_from',    'label' => 'Datum od',     'type' => 'date'],
            ['key' => 'date_to',      'label' => 'Datum do',     'type' => 'date'],
            ['key' => 'member_name',  'label' => 'Člen',         'type' => 'text'],
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
                case 'description':
                    $query->where('transfers.text', 'like', "%{$value}%");
                    break;
                case 'amount_from':
                    $query->where('transfers.amount', '>=', (float) $value);
                    break;
                case 'amount_to':
                    $query->where('transfers.amount', '<=', (float) $value);
                    break;
                case 'date_from':
                    $query->where('transfers.datetime', '>=', $value . ' 00:00:00');
                    break;
                case 'date_to':
                    $query->where('transfers.datetime', '<=', $value . ' 23:59:59');
                    break;
                case 'member_name':
                    $query->whereHas('member', fn($q) => $q->where('name', 'like', "%{$value}%"));
                    break;
            }
        }
    }
}
