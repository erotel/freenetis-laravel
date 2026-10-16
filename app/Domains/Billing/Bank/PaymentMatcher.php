<?php

namespace App\Domains\Billing\Bank;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\BankTransfer;
use App\Models\Member;
use App\Models\Setting;
use App\Models\VariableSymbol;
use Illuminate\Support\Facades\DB;

/**
 * Párování příchozí platby na člena podle variabilního symbolu.
 *
 * VS se porovnává jako ŘETĚZEC — sloupec je varchar s „12345+U" u ukončených;
 * int parametr by MariaDB přetypovala a „12345+U" by se spároval na ukončeného.
 * Nespáruje se: čekatel (17/18, nepodepsaná přihláška/smlouva) a platba na jiný
 * bankovní účet, než je pro typ člena nastaven (bank_account_member_type_<typ>);
 * důvod se zapíše do komentáře bankovního převodu (Kohana pvfree_filter_member_by_bank_account).
 */
class PaymentMatcher
{
    /** @return ?array{memberId:int, creditAccountId:int} */
    public function match(BankTransfer $bt, BankAccount $importAccount): ?array
    {
        if ($bt->variable_symbol === null) {
            return null;
        }
        $vs = VariableSymbol::where('variable_symbol', (string) $bt->variable_symbol)->first();
        if (!$vs) {
            return null;
        }
        // variable_symbols.account_id ukazuje přímo na kreditní účet 221100
        $credit = DB::table('accounts')->where('id', $vs->account_id)->where('account_attribute_id', 221100)->first();
        if (!$credit || !$this->allowed($vs->account_id, $importAccount, $bt)) {
            return null;
        }
        return ['memberId' => (int) $credit->member_id, 'creditAccountId' => (int) $credit->id];
    }

    private function allowed(int $accountId, BankAccount $importAccount, BankTransfer $bt): bool
    {
        $account = Account::find($accountId);
        $member  = ($account && $account->member_id) ? Member::find($account->member_id) : null;
        if (!$member) {
            return true; // typ nelze určit → povolit
        }

        if (in_array((int) $member->type, [17, 18])) {
            $bt->comment = 'Čekající člen/zákazník — smlouva/přihláška nepodepsána, platba nespárována.';
            return false;
        }

        $type     = (int) $member->type;
        $expected = (int) Setting::get(sprintf('bank_account_member_type_%d', $type), 0);
        if ($expected === 0 || $importAccount->id === $expected) {
            return true;
        }

        $bt->comment = sprintf(
            'Platba patří %s (member_id=%d, typ=%d), ale přišla na špatný účet (%s). Očekáván účet id=%d.',
            $member->name, $member->id, $type, $importAccount->full_account_number, $expected
        );
        return false;
    }
}
