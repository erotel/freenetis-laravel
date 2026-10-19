<?php

namespace App\Domains\Notifications\Queries;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Seznam fronty e-mailů podle stavu (0 čekající, 1 odeslané) — výchozí jen
 * z posledních 200 (fronta má statisíce řádků), s `all` celá; filtry
 * odesílatel / příjemce / předmět (LIKE). Nejnovější první.
 */
class EmailQueueListQuery
{
    public const RECENT = 200;

    public function paginate(int $state, bool $all, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = $all
            ? DB::table('email_queues')->where('state', $state)
            : DB::table('email_queues')
                ->joinSub(DB::table('email_queues')->select('id')->where('state', $state)->orderByDesc('id')->limit(self::RECENT),
                    'last200', fn ($j) => $j->on('email_queues.id', '=', 'last200.id'))
                ->select('email_queues.*')
                ->where('email_queues.state', $state);

        self::applyFilters($query, $filters);
        return $query->orderByDesc('email_queues.id')->paginate($perPage)->withQueryString();
    }

    /** @param array{from?: ?string, to?: ?string, subject?: ?string} $filters */
    public static function applyFilters($query, array $filters): void
    {
        foreach (['from', 'to', 'subject'] as $col) {
            if ($f = $filters[$col] ?? null) {
                $query->where($col, 'like', "%{$f}%");
            }
        }
    }
}
