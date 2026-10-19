<?php

namespace App\Domains\Billing\Repositories;

use App\Http\Filters\TransferFilter;
use App\Models\Transfer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** Prohlížení účetních převodů (transfers). Zápis převodů viz LedgerRepository / BankLedgerRepository. */
class TransferRepository
{
    /** Přehled; volitelně jen převody účtu (z / na) a člena, rozšířené filtry. */
    public function search(?int $accountId, ?int $memberId, array $filters, string $sort, string $dir, int $perPage): LengthAwarePaginator
    {
        $query = Transfer::with(['origin.member', 'destination.member', 'member'])
            ->orderBy($sort, $dir);

        if ($accountId) {
            $query->where(fn ($q) => $q->where('origin_id', $accountId)->orWhere('destination_id', $accountId));
        }
        if ($memberId) {
            $query->where('member_id', $memberId);
        }
        if (!empty($filters)) {
            TransferFilter::apply($query, $filters);
        }

        return $query->paginate($perPage)->withQueryString();
    }

    public function ofAccount(int $accountId, string $sort, string $dir, int $perPage): LengthAwarePaginator
    {
        return Transfer::where(fn ($q) => $q->where('origin_id', $accountId)->orWhere('destination_id', $accountId))
            ->with(['origin.member', 'destination.member'])
            ->orderBy($sort, $dir)
            ->paginate($perPage)
            ->withQueryString();
    }

    /** Detail: protistrany, předchozí převod a bankovní pohyby, závislé převody. */
    public function findForDetailOrFail(int $id): Transfer
    {
        return Transfer::with([
            'origin.member',
            'destination.member',
            'member',
            'user',
            'previousTransfer.origin.member',
            'previousTransfer.destination.member',
            'previousTransfer.member',
            'previousTransfer.user',
            'previousTransfer.bankTransfer.originAccount',
            'previousTransfer.bankTransfer.destinationAccount',
            'previousTransfer.bankTransfer.bankStatement',
            'bankTransfer.originAccount',
            'bankTransfer.destinationAccount',
            'bankTransfer.bankStatement',
            'dependentTransfers.origin',
            'dependentTransfers.destination',
        ])->findOrFail($id);
    }
}
