<?php

namespace App\Domains\Billing\Repositories;

use App\Models\Account;
use App\Models\AccountAttribute;
use App\Models\Transfer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** Účetní účty (accounts): kreditní účty členů, projektové a systémové účty. */
class AccountRepository
{
    /** @param string $type all | credit (člena) | project | other (systémové) */
    public function paginate(string $type, string $q, string $sort, string $dir, int $perPage): LengthAwarePaginator
    {
        $query = Account::with(['member', 'accountAttribute']);

        match ($type) {
            'credit'  => $query->whereNotNull('member_id'),
            'project' => $query->where('account_attribute_id', AccountAttribute::PROJECT_ATTRIBUTE_ID),
            'other'   => $query->whereNull('member_id')
                               ->where('account_attribute_id', '!=', AccountAttribute::PROJECT_ATTRIBUTE_ID),
            default   => null,
        };

        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $query->where(function ($w) use ($like, $q) {
                $w->where('name', 'like', $like)
                  ->orWhereHas('member', fn ($m) => $m->where('name', 'like', $like));
                if (ctype_digit($q)) {
                    $w->orWhere('id', (int) $q)
                      ->orWhere('member_id', (int) $q);
                }
            });
        }

        return $query->orderBy($sort, $dir)->paginate($perPage)->withQueryString();
    }

    public function findOrFail(int $id): Account
    {
        return Account::findOrFail($id);
    }

    public function findWithRelationsOrFail(int $id): Account
    {
        return Account::with(['member', 'accountAttribute'])->findOrFail($id);
    }

    public function findWithAttributeOrFail(int $id): Account
    {
        return Account::with('accountAttribute')->findOrFail($id);
    }

    /** @return array{0: float, 1: float} [příjmy, výdaje] */
    public function totals(int $accountId): array
    {
        return [
            Transfer::where('destination_id', $accountId)->sum('amount'),
            Transfer::where('origin_id', $accountId)->sum('amount'),
        ];
    }

    public function createProject(string $name, ?string $comment): Account
    {
        return Account::create([
            'name'                 => $name,
            'comment'              => $comment,
            'account_attribute_id' => AccountAttribute::PROJECT_ATTRIBUTE_ID,
            'member_id'            => null,
            'balance'              => 0,
        ]);
    }

    public function update(Account $account, array $data): void
    {
        $account->update($data);
    }
}
