<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\VariableSymbol;
use App\Services\AclService;
use Illuminate\Http\Request;

class VariableSymbolController extends Controller
{
    private const ACL_SECTION = 'Variable_Symbols_Controller';
    private const ACL_VALUE   = 'variable_symbols';

    public function __construct(private AclService $acl) {}

    private function can(string $action): bool
    {
        return $this->acl->hasAccess(auth()->id(), $action, self::ACL_SECTION, self::ACL_VALUE);
    }

    public function showByAccount(int $accountId)
    {
        abort_unless($this->can('view_all'), 403);

        $account = Account::with(['member', 'variableSymbols'])->findOrFail($accountId);

        return view('variable_symbols.show_by_account', [
            'account'   => $account,
            'symbols'   => $account->variableSymbols,
            'canAdd'    => $this->can('new_all'),
            'canDelete' => $this->can('delete_all'),
        ]);
    }

    public function store(Request $request, int $accountId)
    {
        abort_unless($this->can('new_all'), 403);

        $account = Account::findOrFail($accountId);

        $request->validate([
            'variable_symbol' => [
                'required',
                'string',
                'regex:/^[0-9]{1,10}$/',
                'unique:variable_symbols,variable_symbol',
            ],
        ], [
            'variable_symbol.regex'  => 'Variabilní symbol musí být číslo o délce 1–10 číslic.',
            'variable_symbol.unique' => 'Tento variabilní symbol je již použit.',
        ]);

        VariableSymbol::create([
            'account_id'      => $account->id,
            'variable_symbol' => $request->variable_symbol,
        ]);

        session()->flash('success', 'Variabilní symbol byl úspěšně přidán.');

        return redirect()->route('variable_symbols.by_account', $accountId);
    }

    public function destroy(int $id)
    {
        abort_unless($this->can('delete_all'), 403);

        $symbol = VariableSymbol::findOrFail($id);
        $accountId = $symbol->account_id;
        $symbol->delete();

        session()->flash('success', 'Variabilní symbol byl smazán.');

        return redirect()->route('variable_symbols.by_account', $accountId);
    }
}
