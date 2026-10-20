<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Audit\AuditLogger;
use App\Domains\Billing\Bank\OutgoingPaymentReconciler;
use App\Domains\Billing\Bank\PaymentMatcher;
use App\Domains\Billing\Events\PaymentReceived;
use App\Domains\Billing\Repositories\BankLedgerRepository as Ledger;
use App\Models\BankAccount;
use App\Models\BankTransfer;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Import rozparsovaného výpisu FIO (FioCsvParser) do bankovního účetnictví —
 * Kohana Fio_Bank_Statement_File_Importer::store():
 *
 *   příchozí  členské příspěvky 684000 (vklad pokladnou: pokladna 211000) → banka;
 *             spárovaná na člena (PaymentMatcher) navíc banka → kredit člena
 *   úrok      úroky 644000 → banka
 *   odchozí   banka → dodavatelé 321000 (patří organizaci) + párování „OP #id"
 *
 * Duplicity podle kódu pohybu se přeskočí. Vše v jedné transakci; po commitu
 * {@see PaymentReceived} pro každou spárovanou platbu (doklad, dohnání poplatků) —
 * chyba jedné neshodí ostatní (převody už jsou zapsané a FIO je znovu nevrátí).
 */
class ImportBankStatementAction
{
    /** Jak dlouho čekat na doběhnutí jiného importu téhož účtu. */
    public int $lockWaitSeconds = 120;

    public function __construct(
        private Ledger $ledger,
        private PaymentMatcher $matcher,
        private OutgoingPaymentReconciler $reconciler,
    ) {
    }

    /**
     * Importy jednoho bankovního účtu běží po sobě (zámek): deduplikace podle
     * kódu pohybu čte bez zámku a kód nemá UNIQUE index — souběžný ruční import
     * a plánovač (:55) by jinak tutéž platbu připsaly dvakrát.
     *
     * @return array{0:int, 1:int} [importováno, přeskočeno duplicit]
     * @throws \RuntimeException když jiný import účtu neskončí včas
     */
    public function handle(BankAccount $account, array $header, array $rows, string $source, int $userId): array
    {
        try {
            return Cache::lock('bank-import:' . $account->id, 600)
                ->block($this->lockWaitSeconds, fn () => $this->import($account, $header, $rows, $source, $userId));
        } catch (LockTimeoutException) {
            throw new \RuntimeException('Na tomto účtu právě běží jiný import výpisu — zkuste to za chvíli znovu.');
        }
    }

    /** @return array{0:int, 1:int} */
    private function import(BankAccount $account, array $header, array $rows, string $source, int $userId): array
    {
        $imported = 0;
        $skipped  = 0;
        $payments = []; // [memberId, amount, bankTransferId]

        DB::transaction(function () use ($account, $header, $rows, $source, $userId, &$imported, &$skipped, &$payments) {
            $stmt = $this->ledger->createStatement($account->id, $userId, $source, $header);

            $bankId      = $this->ledger->linkedAccountId($account->id, Ledger::BANK);
            $interestsId = $this->ledger->linkedAccountId($account->id, Ledger::BANK_INTERESTS);
            $memberFees  = $this->ledger->systemAccountId(Ledger::MEMBER_FEES);
            $suppliers   = $this->ledger->systemAccountId(Ledger::SUPPLIERS);
            $cash        = $this->ledger->systemAccountId(Ledger::CASH);
            if (!$bankId) {
                Log::error("ImportController: bank account id={$account->id} has no linked accounting account (accounts_bank_accounts pivot missing). Double-entry transfers will be skipped.");
            }
            $now = now()->format('Y-m-d H:i:s');

            foreach ($rows as $row) {
                $code = isset($row['id_pohybu']) && $row['id_pohybu'] !== '' ? (int) $row['id_pohybu'] : null;
                if ($code !== null && $this->ledger->transactionImported($code)) {
                    $skipped++;
                    continue;
                }

                $amount     = (float) ($row['castka'] ?? 0.0);
                $isIncoming = $amount >= 0;
                $absAmount  = abs($amount);
                $datetime   = $row['datum'] ?? $now;
                $text       = $row['zprava'] ?? ($row['identifikace'] ?? null);

                $bt = new BankTransfer();
                $bt->bank_statement_id = $stmt->id;
                $bt->transaction_code  = $code;
                $bt->variable_symbol   = isset($row['vs']) && $row['vs'] !== '' ? (int) $row['vs'] : null;
                $bt->constant_symbol   = $row['ks'] ?? null;
                $bt->specific_symbol   = $row['ss'] ?? null;
                $bt->comment           = isset($row['identifikace']) && $row['identifikace'] !== '' ? $row['identifikace'] : ($row['zprava'] ?? null);

                $match   = null;
                $payment = null; // index platby přidané TÍMTO řádkem
                if (!$isIncoming) {
                    $bt->origin_id      = $account->id;
                    $bt->destination_id = null;
                    if ($bankId && $suppliers) {
                        $bt->transfer_id = $this->ledger->post($bankId, $suppliers, null, null, $userId, null, $datetime, $now, $text, $absAmount);
                    }
                } elseif (($row['typ'] ?? '') === 'Připsaný úrok') {
                    $bt->origin_id      = null;
                    $bt->destination_id = $account->id;
                    if ($interestsId && $bankId) {
                        $bt->transfer_id = $this->ledger->post($interestsId, $bankId, null, null, $userId, null, $datetime, $now, $row['typ'], $absAmount);
                    }
                } else {
                    $bt->destination_id = $account->id;
                    $bt->origin_id = (!empty($row['protiucet']) && !empty($row['kod_banky']))
                        ? $this->ledger->counterparty($row['protiucet'], $row['kod_banky'], $row['nazev_protiuctu'] ?? null)->id
                        : null;

                    $origin = (($row['typ'] ?? '') === 'Vklad pokladnou') ? $cash : $memberFees;
                    $match  = $this->matcher->match($bt, $account);
                    if (!$match && $bt->variable_symbol !== null && empty($bt->comment)) {
                        $bt->comment = 'Neidentifikovaný převod: VS=' . (string) $bt->variable_symbol;
                    }

                    if ($origin && $bankId) {
                        $bt->transfer_id = $this->ledger->post($origin, $bankId, null, $match['memberId'] ?? null, $userId, null, $datetime, $now, $text, $absAmount);
                        if ($match) {
                            $this->ledger->post($bankId, $match['creditAccountId'], $bt->transfer_id, $match['memberId'], $userId, null,
                                $datetime, $now, 'Přiřazení platby', $absAmount);
                            $payments[] = ['memberId' => $match['memberId'], 'amount' => $absAmount];
                            $payment    = array_key_last($payments);
                        }
                    }
                }

                $bt->save();
                $imported++;

                if (!$isIncoming) {
                    if ($bt->transfer_id) {
                        $this->ledger->assignToOrganization((int) $bt->transfer_id);
                    }
                    $this->reconciler->reconcile($bt, $account, (string) ($row['identifikace'] ?? $row['zprava'] ?? ''), $absAmount);
                }

                // Id bankovního převodu jen k platbě z tohoto řádku (spárovaný vklad
                // pokladnou bez účtu pokladny platbu nepřidá a cizí id nesmí přepsat).
                if ($payment !== null) {
                    $payments[$payment]['bankTransferId'] = $bt->id;
                }
            }
        });

        foreach ($payments as $p) {
            if ($p['amount'] <= 0) {
                continue;
            }
            try {
                PaymentReceived::dispatch($p['memberId'], $p['amount'], $p['bankTransferId'], $account);
            } catch (\Throwable $e) {
                Log::error(
                    sprintf('handlePostImport failed for bank_transfer #%d (member #%d, amount %.2f): %s',
                        $p['bankTransferId'], $p['memberId'], $p['amount'], $e->getMessage()),
                    ['trace' => $e->getTraceAsString()]
                );
            }
        }

        // Audit dávky (jednotlivé postingy jsou v transfers).
        if ($imported > 0) {
            AuditLogger::log('created', 'bank_transfers', $account->id, null, [
                'bank_account_id' => $account->id,
                'imported'        => $imported,
                'skipped'         => $skipped,
                'source'          => $source,
            ]);
        }

        return [$imported, $skipped];
    }
}
