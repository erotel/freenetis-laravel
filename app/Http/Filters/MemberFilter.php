<?php

namespace App\Http\Filters;

use App\Helpers\MemberType;
use Illuminate\Support\Facades\DB;

class MemberFilter
{
    public static function fields(bool $tvEnabled = false): array
    {
        $towns   = DB::table('towns')->orderBy('town')->pluck('town', 'id')->toArray();
        $streets = DB::table('streets')->orderBy('street')->pluck('street', 'id')->toArray();

        $fields = [
            ['key' => 'name',            'label' => 'Jméno',              'type' => 'text'],
            ['key' => 'id',              'label' => 'ID',                 'type' => 'number'],
            ['key' => 'type',            'label' => 'Typ',                'type' => 'select', 'values' => MemberType::labels()],
            ['key' => 'balance',         'label' => 'Kredit (Kč)',        'type' => 'number'],
            ['key' => 'payment_blocked', 'label' => 'Blokace platby',     'type' => 'select', 'values' => [
                ''  => '— vše —',
                '1' => 'Blokovaní',
                '0' => 'Neblokovaní',
            ]],
            ['key' => 'variable_symbol', 'label' => 'Variabilní symbol',  'type' => 'text'],
            ['key' => 'entrance_date',   'label' => 'Datum vstupu',       'type' => 'date'],
            ['key' => 'leaving_date',    'label' => 'Datum odchodu',      'type' => 'date'],
            ['key' => 'town',            'label' => 'Město',              'type' => 'select', 'values' => $towns],
            ['key' => 'street',          'label' => 'Ulice',              'type' => 'text'],
            ['key' => 'street_number',   'label' => 'Číslo popisné',      'type' => 'text'],
            ['key' => 'redirect_type',   'label' => 'Přesměrování',       'type' => 'select', 'values' => [
                ''   => 'Žádné',
                '4'  => 'Přerušení členství',
                '5'  => 'Dlužník',
                '6'  => 'Upozornění na placení',
                '7'  => 'Nepovolené PM',
                '16' => 'Expirovaný test',
                '20' => 'Velký dlužník',
            ]],
            ['key' => 'whitelisted',     'label' => 'Bílá listina',       'type' => 'select', 'values' => [
                ''          => '— vše —',
                'permanent' => 'Trvalá',
                'temporary' => 'Dočasná',
                'none'      => 'Žádná',
            ]],
            ['key' => 'registration',    'label' => 'Registrace',         'type' => 'select', 'values' => [
                '' => '— vše —', '1' => 'Ano', '0' => 'Ne',
            ]],
        ];

        if ($tvEnabled) {
            $fields[] = ['key' => 'tv', 'label' => 'SledovaniTV', 'type' => 'select', 'values' => [
                'active'   => 'Aktivní',
                'inactive' => 'Vypršelá',
                'never'    => 'Nikdy synced',
            ]];
        }

        return $fields;
    }

    public static function apply($query, array $filters): void
    {
        foreach ($filters as $filter) {
            if (empty($filter['active'])) continue;
            $field = $filter['field'] ?? '';
            $op    = $filter['op']    ?? 'eq';
            $value = $filter['value'] ?? '';
            if ($field === '') continue;
            self::applyOne($query, $field, $op, $value);
        }
    }

    private static function applyOne($query, string $field, string $op, $value): void
    {
        switch ($field) {
            case 'name':
                self::text($query, 'members.name', $op, $value);
                break;

            case 'id':
                self::numeric($query, 'members.id', $op, $value);
                break;

            case 'type':
                self::select($query, 'members.type', $op, $value);
                break;

            case 'registration':
                self::select($query, 'members.registration', $op, $value);
                break;

            case 'entrance_date':
                self::numeric($query, 'members.entrance_date', $op, $value);
                break;

            case 'leaving_date':
                self::numeric($query, 'members.leaving_date', $op, $value);
                break;

            case 'town':
                $method = $op === 'neq' ? 'whereDoesntHave' : 'whereHas';
                $query->$method('addressPoint', function ($q) use ($value) {
                    $q->where('town_id', (int) $value);
                });
                break;

            case 'street':
                $method = $op === 'neq' ? 'whereDoesntHave' : 'whereHas';
                $query->$method('addressPoint.street', function ($q) use ($op, $value) {
                    self::text($q, 'streets.street', $op === 'neq' ? 'eq' : $op, $value);
                });
                break;

            case 'street_number':
                self::text($query, 'address_points.street_number', $op, $value,
                    fn($q) => $q->join('address_points as ap_fn', 'ap_fn.id', '=', 'members.address_point_id'));
                break;

            case 'balance':
                $sqlOp = self::numericSqlOp($op);
                $query->whereRaw(
                    "(SELECT a.balance FROM accounts a WHERE a.member_id = members.id AND a.account_attribute_id = 221100 LIMIT 1) $sqlOp ?",
                    [(float) $value]
                );
                break;

            case 'payment_blocked':
                // Blokace kvůli nedostatku kreditu (members.payment_blocked).
                // Hodnota je explicitní 0/1, operátor ignorujeme.
                if ($value === '0' || $value === '1') {
                    $query->where('members.payment_blocked', (int) $value);
                }
                break;

            case 'variable_symbol':
                if ($op === 'empty') {
                    $query->whereNotExists(fn($q) => $q->select(DB::raw(1))
                        ->from('accounts as a')->join('variable_symbols as vs', 'vs.account_id', '=', 'a.id')
                        ->whereColumn('a.member_id', 'members.id'));
                } elseif ($op === 'not_empty') {
                    $query->whereExists(fn($q) => $q->select(DB::raw(1))
                        ->from('accounts as a')->join('variable_symbols as vs', 'vs.account_id', '=', 'a.id')
                        ->whereColumn('a.member_id', 'members.id'));
                } else {
                    [$sqlOp, $sqlVal] = self::textSqlOp($op, $value);
                    $query->whereExists(fn($q) => $q->select(DB::raw(1))
                        ->from('accounts as a')->join('variable_symbols as vs', 'vs.account_id', '=', 'a.id')
                        ->whereColumn('a.member_id', 'members.id')
                        ->where('vs.variable_symbol', $sqlOp, $sqlVal));
                }
                break;

            case 'redirect_type':
                if ($value === '' || $value === 'none') {
                    $query->whereNotExists(fn($q) => $q->select(DB::raw(1))
                        ->from('messages_ip_addresses as mia')
                        ->join('ip_addresses as ip', 'ip.id', '=', 'mia.ip_address_id')
                        ->whereColumn('ip.member_id', 'members.id'));
                } else {
                    $query->whereExists(fn($q) => $q->select(DB::raw(1))
                        ->from('messages_ip_addresses as mia')
                        ->join('ip_addresses as ip', 'ip.id', '=', 'mia.ip_address_id')
                        ->join('messages as msg', 'msg.id', '=', 'mia.message_id')
                        ->whereColumn('ip.member_id', 'members.id')
                        ->where('msg.type', $op === 'neq' ? '!=' : '=', (int) $value));
                }
                break;

            case 'tv':
                // Operátor (eq/neq) ignorujeme — select má 3 vlastní stavy.
                if ($value === 'active') {
                    $query->where('members.tv_active', 1);
                } elseif ($value === 'inactive') {
                    $query->where('members.tv_active', 0)->whereNotNull('members.tv_synced_at');
                } elseif ($value === 'never') {
                    $query->whereNull('members.tv_synced_at');
                }
                break;

            case 'whitelisted':
                $today = now()->toDateString();
                $hasWl = fn($q) => $q->select(DB::raw(1))
                    ->from('members_whitelists as mw')
                    ->whereColumn('mw.member_id', 'members.id')
                    ->where('mw.since', '<=', $today)
                    ->where('mw.until', '>=', $today);

                if ($value === 'none') {
                    $query->whereNotExists($hasWl);
                } elseif ($value === 'permanent') {
                    $query->whereExists(fn($q) => $hasWl($q)
                        ->where(fn($q2) => $q2->where('mw.permanent', 1)->orWhere('mw.until', '9999-12-31')));
                } elseif ($value === 'temporary') {
                    $query->whereExists(fn($q) => $hasWl($q)
                        ->where('mw.permanent', 0)->where('mw.until', '!=', '9999-12-31'));
                } else {
                    $query->whereExists($hasWl);
                }
                break;
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private static function text($query, string $col, string $op, $value, ?callable $join = null): void
    {
        if ($join) $join($query);
        match ($op) {
            'contains'     => $query->where($col, 'like', "%{$value}%"),
            'not_contains' => $query->where($col, 'not like', "%{$value}%"),
            'eq'           => $query->where($col, '=', $value),
            'neq'          => $query->where($col, '!=', $value),
            'empty'        => $query->where(fn($q) => $q->whereNull($col)->orWhere($col, '=')),
            'not_empty'    => $query->whereNotNull($col)->where($col, '!=', ''),
            default        => null,
        };
    }

    private static function numeric($query, string $col, string $op, $value): void
    {
        $sqlOp = self::numericSqlOp($op);
        if ($sqlOp) $query->where($col, $sqlOp, $value);
    }

    private static function select($query, string $col, string $op, $value): void
    {
        if ($value === '') return;
        $query->where($col, $op === 'neq' ? '!=' : '=', $value);
    }

    private static function numericSqlOp(string $op): string
    {
        return match($op) {
            'eq'  => '=',  'neq' => '!=',
            'lt'  => '<',  'lte' => '<=',
            'gt'  => '>',  'gte' => '>=',
            default => '=',
        };
    }

    private static function textSqlOp(string $op, $value): array
    {
        return match($op) {
            'contains'     => ['like',     "%{$value}%"],
            'not_contains' => ['not like', "%{$value}%"],
            'neq'          => ['!=',       $value],
            default        => ['=',        $value],
        };
    }
}
