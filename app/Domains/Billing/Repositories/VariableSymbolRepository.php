<?php

namespace App\Domains\Billing\Repositories;

use App\Models\Account;
use App\Models\VariableSymbol;

/** Variabilní symboly účtů (variable_symbols) — párování příchozích plateb. */
class VariableSymbolRepository
{
    public function accountWithSymbolsOrFail(int $accountId): Account
    {
        return Account::with(['member', 'variableSymbols'])->findOrFail($accountId);
    }

    public function accountOrFail(int $accountId): Account
    {
        return Account::findOrFail($accountId);
    }

    public function findOrFail(int $id): VariableSymbol
    {
        return VariableSymbol::findOrFail($id);
    }

    public function create(int $accountId, string $variableSymbol): VariableSymbol
    {
        return VariableSymbol::create(['account_id' => $accountId, 'variable_symbol' => $variableSymbol]);
    }

    public function delete(VariableSymbol $symbol): void
    {
        $symbol->delete();
    }
}
