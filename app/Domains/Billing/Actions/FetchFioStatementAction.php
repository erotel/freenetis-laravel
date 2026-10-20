<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Bank\FioApiService;
use App\Domains\Billing\Bank\FioCsvParser;
use App\Domains\Billing\Repositories\BankLedgerRepository;
use App\Models\BankAccount;
use App\Models\Setting;

/**
 * Stažení výpisu z FIO API (token per bankovní účet v nastavení) a rozparsování.
 * „Nové pohyby": záložka FIO se nejdřív posune na nejvyšší už importovaný kód
 * pohybu (Kohana before_download), aby API vrátilo jen nové.
 */
class FetchFioStatementAction
{
    public function __construct(
        private FioApiService $fio,
        private FioCsvParser $parser,
        private BankLedgerRepository $ledger,
    ) {
    }

    public static function token(int $bankAccountId): ?string
    {
        return Setting::get('fio_api_token_bank_account_' . $bankAccountId) ?: null;
    }

    /**
     * @return array{header: array, rows: array, errors: array}
     * @throws \RuntimeException chyba API / parsování
     */
    public function newSince(BankAccount $account, string $token): array
    {
        $lastId = $this->ledger->lastTransactionCode($account->id);
        if ($lastId) {
            $this->fio->setLastId($token, $lastId);
        }
        return $this->parser->parse($this->fio->fetchLast($token));
    }

    /**
     * @return array{header: array, rows: array, errors: array}
     * @throws \RuntimeException
     */
    public function period(string $token, string $from, string $to): array
    {
        return $this->parser->parse($this->fio->fetchPeriod($token, $from, $to));
    }
}
