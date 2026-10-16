<?php

namespace App\Domains\Contracts\Queries;

use App\Domains\Contracts\Repositories\ContractRepository;
use App\Models\Contract;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Seznam smluv a odznak nepodepsaných (read model). Smlouvy bývalých členů
 * (15/16) se skrývají — v seznamu kromě ukončených (výpověď), v odznaku vždy;
 * smlouvy bez člena zůstávají.
 */
class ContractListQuery
{
    public const STATUSES = ['draft', 'otp_sent', 'otp_verified', 'signed', 'canceled', 'terminated'];
    public const UNSIGNED = ['draft', 'otp_sent', 'otp_verified'];

    public function __construct(private ContractRepository $contracts)
    {
    }

    /** Hledání v čísle smlouvy, jménu a VS strany, číselně i v member_id; filtr stavu. */
    public function paginate(string $q, string $status): LengthAwarePaginator
    {
        // Bez filtru party.active — u návrhu je 0 a view by ukázal jen „Člen #…".
        $query = $this->withoutFormer(Contract::with('parties')->orderByDesc('id'), true);

        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            $query->where(function ($w) use ($like, $q) {
                $w->where('contract_no', 'like', $like)
                  ->orWhereHas('parties', fn ($pq) => $pq->where('full_name', 'like', $like)->orWhere('variable_symbol', 'like', $like));
                if (ctype_digit($q)) {
                    $w->orWhere('member_id', (int) $q);
                }
            });
        }
        if ($status !== '' && in_array($status, self::STATUSES, true)) {
            $query->where('status', $status);
        }

        return $query->paginate(50)->withQueryString();
    }

    /** Počty po stavech (bez hledání, se stejným skrytím bývalých jako seznam). @return array<string,int> */
    public function statusCounts(): array
    {
        return $this->withoutFormer(Contract::selectRaw('status, COUNT(*) AS cnt')->groupBy('status'), true)
            ->pluck('cnt', 'status')->all();
    }

    /** Odznak v menu: nepodepsané smlouvy aktivních členů. */
    public function countUnsigned(): int
    {
        return $this->withoutFormer(Contract::whereIn('status', self::UNSIGNED), false)->count();
    }

    private function withoutFormer($query, bool $keepTerminated)
    {
        $formerIds = $this->contracts->formerMemberIds();
        if (!empty($formerIds)) {
            $query->where(function ($w) use ($formerIds, $keepTerminated) {
                $w->whereNull('member_id')->orWhereNotIn('member_id', $formerIds);
                if ($keepTerminated) {
                    $w->orWhere('status', 'terminated');
                }
            });
        }
        return $query;
    }
}
