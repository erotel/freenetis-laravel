<?php

namespace App\Domains\Billing\Repositories;

use App\Models\BankAccount;
use App\Models\BankStatement;
use App\Models\BankTransfer;
use Illuminate\Support\Facades\DB;

/**
 * Bankovní výpisy a podvojné účetnictví plateb (bank_statements, bank_transfers,
 * transfers, accounts, accounts_bank_accounts). Kódy účtů = Kohana Account_attribute_Model.
 */
class BankLedgerRepository
{
    public const BANK           = 221000; // bankovní účet (per bankovní účet přes pivot)
    public const BANK_INTERESTS = 644000; // úroky (per bankovní účet)
    public const MEMBER_FEES    = 684000; // přijaté členské příspěvky
    public const SUPPLIERS      = 321000; // dodavatelé (odchozí platby)
    public const CASH           = 211000; // pokladna
    public const CREDIT         = 221100; // kreditní účet člena

    /** Účetní účet daného druhu propojený s bankovním účtem (accounts_bank_accounts). */
    public function linkedAccountId(int $bankAccountId, int $attribute): ?int
    {
        $id = DB::table('accounts_bank_accounts as aba')
            ->join('accounts as a', 'a.id', '=', 'aba.account_id')
            ->where('aba.bank_account_id', $bankAccountId)
            ->where('a.account_attribute_id', $attribute)
            ->value('a.id');
        return $id ? (int) $id : null;
    }

    /** Systémový účet daného druhu (první s tímto atributem). */
    public function systemAccountId(int $attribute): ?int
    {
        $id = DB::table('accounts')->where('account_attribute_id', $attribute)->value('id');
        return $id ? (int) $id : null;
    }

    public function createStatement(int $bankAccountId, int $userId, string $type, array $header): BankStatement
    {
        $stmt = new BankStatement();
        $stmt->bank_account_id = $bankAccountId;
        $stmt->user_id         = $userId;
        $stmt->type            = $type;
        $stmt->from            = $header['dateStart'] ?? null;
        $stmt->to              = $header['dateEnd']   ?? null;
        $stmt->opening_balance = $header['openingBalance'] ?? 0;
        $stmt->closing_balance = $header['closingBalance'] ?? 0;
        $stmt->save();
        return $stmt;
    }

    public function transactionImported(int $transactionCode): bool
    {
        return BankTransfer::where('transaction_code', $transactionCode)->exists();
    }

    /** Nejvyšší kód pohybu importovaný pro bankovní účet (záložka FIO API), nebo null. */
    public function lastTransactionCode(int $bankAccountId): ?int
    {
        $max = BankTransfer::whereHas('bankStatement', fn ($q) => $q->where('bank_account_id', $bankAccountId))
            ->whereNotNull('transaction_code')
            ->max('transaction_code');
        return $max ? (int) $max : null;
    }

    /** Bankovní účet protistrany podle čísla + kódu banky (založí se, když chybí). */
    public function counterparty(string $accountNr, string $bankNr, ?string $name): BankAccount
    {
        return BankAccount::firstOrCreate(
            ['account_nr' => $accountNr, 'bank_nr' => $bankNr],
            ['name' => $name ?? ($accountNr . '/' . $bankNr), 'member_id' => null]
        );
    }

    /**
     * Převod s okamžitou úpravou zůstatků obou stran (Kohana Transfer_Model::insert_transfer).
     * @return int id převodu
     */
    public function post(?int $originId, ?int $destinationId, ?int $previousTransferId, ?int $memberId, ?int $userId,
                         ?int $type, string $datetime, string $createdAt, ?string $text, float $amount): int
    {
        $id = (int) DB::table('transfers')->insertGetId([
            'origin_id'            => $originId,
            'destination_id'       => $destinationId,
            'previous_transfer_id' => $previousTransferId,
            'member_id'            => $memberId,
            'user_id'              => $userId,
            'type'                 => $type,
            'datetime'             => $datetime,
            'creation_datetime'    => $createdAt,
            'text'                 => $text,
            'amount'               => $amount,
        ]);
        if ($originId) {
            DB::table('accounts')->where('id', $originId)->decrement('balance', $amount);
        }
        if ($destinationId) {
            DB::table('accounts')->where('id', $destinationId)->increment('balance', $amount);
        }
        return $id;
    }

    /** Převod patří organizaci (member 1) — nezobrazí se mezi neidentifikovanými. */
    public function assignToOrganization(int $transferId): void
    {
        DB::table('transfers')->where('id', $transferId)->update(['member_id' => 1]);
    }

    /** Výpisy bankovního účtu, nejnovější první. */
    public function statementsOfAccount(int $bankAccountId, int $perPage)
    {
        return BankStatement::where('bank_account_id', $bankAccountId)
            ->with('user')
            ->orderBy('from', 'desc')
            ->paginate($perPage);
    }

    public function bankAccountOrFail(int $id): BankAccount
    {
        return BankAccount::findOrFail($id);
    }

    public function statementWithTransfersOrFail(int $id): BankStatement
    {
        return BankStatement::with('bankTransfers')->findOrFail($id);
    }

    /** Kolik pohybů výpisu je zaúčtovaných (navázaný převod v transfers stále existuje). */
    public function postedTransferCount(BankStatement $statement): int
    {
        return BankTransfer::where('bank_statement_id', $statement->id)
            ->whereIn('transfer_id', DB::table('transfers')->select('id'))
            ->count();
    }

    /** Smaže výpis; jeho bankovní pohyby soft-delete. @return int počet smazaných pohybů */
    public function deleteStatement(BankStatement $statement): int
    {
        $count = $statement->bankTransfers->count();
        $statement->bankTransfers()->delete();
        $statement->delete();
        return $count;
    }

    /** Pohyby všech výpisů bankovního účtu, nejnovější první. */
    public function bankTransfersOfAccount(BankAccount $account, int $perPage)
    {
        return BankTransfer::whereIn('bank_statement_id', $account->bankStatements()->pluck('id'))
            ->with(['bankStatement', 'originAccount', 'destinationAccount', 'transfer'])
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Neidentifikované platby (Kohana Bank_transfer_Model::get_unidentified_transfers):
     * převod bez člena (NULL/0) z účtu MEMBER_FEES (684000) — suspense účet, na který
     * se předúčtují příchozí platby, dokud nejsou přiřazené členovi. Bez filtru
     * na 684000 by sem padaly i interní převody (banka → dodavatelé…).
     */
    public function unidentified(?string $from, ?string $to, int $perPage)
    {
        return BankTransfer::whereNotNull('bank_transfers.transfer_id')
            ->join('transfers', 'transfers.id', '=', 'bank_transfers.transfer_id')
            ->join('accounts as origin_acc', 'origin_acc.id', '=', 'transfers.origin_id')
            ->where('origin_acc.account_attribute_id', self::MEMBER_FEES)
            ->where(function ($q) {
                $q->whereNull('transfers.member_id')->orWhere('transfers.member_id', 0);
            })
            ->when($from, fn ($q) => $q->where('transfers.datetime', '>=', $from . ' 00:00:00'))
            ->when($to,   fn ($q) => $q->where('transfers.datetime', '<=', $to   . ' 23:59:59'))
            ->with(['bankStatement.bankAccount', 'originAccount', 'destinationAccount', 'transfer'])
            ->orderByDesc('transfers.datetime')
            ->select('bank_transfers.*')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function bankTransferOrFail(int $id, array $with = []): BankTransfer
    {
        return BankTransfer::with($with)->findOrFail($id);
    }
}
