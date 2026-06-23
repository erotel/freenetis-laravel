<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\BankStatement;
use App\Models\BankTransfer;
use App\Models\Member;
use App\Models\OutgoingPayment;
use App\Models\Setting;
use App\Models\VariableSymbol;
use App\Models\Transfer;
use App\Http\Controllers\SettingController;
use App\Services\FioApiService;
use App\Services\FioCsvParser;
use App\Services\InvoiceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ImportController extends Controller
{
    private const ACL_SECTION = 'Accounts_Controller';
    private const ACL_VALUE   = 'bank_transfers';

    private function can(string $action): bool
    {
        return $this->aclCheck($action, self::ACL_SECTION, self::ACL_VALUE);
    }

    public function uploadBankFile(int $bankAccountId)
    {
        abort_unless($this->can('new_all'), 403);

        $account = BankAccount::findOrFail($bankAccountId);

        return view('import.bank_file', compact('account'));
    }

    public function importBankFile(Request $request, int $bankAccountId, FioCsvParser $parser)
    {
        abort_unless($this->can('new_all'), 403);

        $account = BankAccount::findOrFail($bankAccountId);

        $request->validate([
            'csv_file' => ['required', 'file', 'mimes:csv,txt', 'max:4096'],
        ]);

        $content = file_get_contents($request->file('csv_file')->getRealPath());

        // Convert encoding if needed (FIO exports UTF-8, but be safe)
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1250');
        }

        try {
            $parsed = $parser->parse($content);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['csv_file' => 'Chyba při parsování CSV: ' . $e->getMessage()]);
        }

        $header = $parsed['header'];
        $rows   = $parsed['rows'];
        $parseErrors = $parsed['errors'];

        if (!empty($parseErrors)) {
            return back()->withErrors(['csv_file' => implode(' ', $parseErrors)]);
        }

        if (empty($rows)) {
            return back()->with('error', 'CSV neobsahuje žádné záznamy.');
        }

        [$imported, $skipped] = $this->persistParsed($account, $header, $rows, 'FIO CSV importer');

        return redirect()
            ->route('bank_accounts.show', $bankAccountId)
            ->with('success', "Import dokončen: importováno {$imported} převodů, přeskočeno {$skipped} duplicit.");
    }

    public function fetchFromFioLast(int $bankAccountId, FioCsvParser $parser, FioApiService $fio)
    {
        abort_unless($this->can('new_all'), 403);

        $account = BankAccount::findOrFail($bankAccountId);
        $token   = Setting::get('fio_api_token_bank_account_' . $bankAccountId);

        if (empty($token)) {
            return back()->withErrors(['token' => 'Není nastaven FIO API token pro tento účet. Nastavte ho v editaci bankovního účtu.']);
        }

        try {
            // Mirror Kohana's before_download: set FIO bookmark to last known transaction_code
            // for this bank account so fetchLast only returns new transactions.
            $lastId = BankTransfer::whereHas('bankStatement', function ($q) use ($bankAccountId) {
                    $q->where('bank_account_id', $bankAccountId);
                })
                ->whereNotNull('transaction_code')
                ->max('transaction_code');

            if ($lastId) {
                $fio->setLastId($token, (int) $lastId);
            }

            $csvContent = $fio->fetchLast($token);
            $parsed     = $parser->parse($csvContent);

            if (!empty($parsed['errors'])) {
                return back()->with('error', implode(' ', $parsed['errors']));
            }
            if (empty($parsed['rows'])) {
                return redirect()->route('bank_transfers.by_account', $bankAccountId)
                    ->with('success', 'Žádné nové transakce.');
            }

            [$imported, $skipped] = $this->persistParsed($account, $parsed['header'], $parsed['rows'], 'FIO API importer');
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('bank_transfers.by_account', $bankAccountId)
            ->with('success', "FIO API: importováno {$imported} převodů, přeskočeno {$skipped} duplicit.");
    }

    public function fetchFromFioPeriod(Request $request, int $bankAccountId, FioCsvParser $parser, FioApiService $fio)
    {
        abort_unless($this->can('new_all'), 403);

        $account = BankAccount::findOrFail($bankAccountId);
        $token   = Setting::get('fio_api_token_bank_account_' . $bankAccountId);

        if (empty($token)) {
            return back()->withErrors(['token' => 'Není nastaven FIO API token pro tento účet. Nastavte ho v editaci bankovního účtu.']);
        }

        $data = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to'   => ['required', 'date', 'after_or_equal:date_from'],
        ]);

        try {
            $csvContent = $fio->fetchPeriod($token, $data['date_from'], $data['date_to']);
            $parsed     = $parser->parse($csvContent);

            if (!empty($parsed['errors'])) {
                return back()->with('error', implode(' ', $parsed['errors']));
            }
            if (empty($parsed['rows'])) {
                return redirect()->route('bank_transfers.by_account', $bankAccountId)
                    ->with('success', 'Žádné transakce za zvolené období.');
            }

            [$imported, $skipped] = $this->persistParsed($account, $parsed['header'], $parsed['rows'], 'FIO API importer');
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('bank_transfers.by_account', $bankAccountId)
            ->with('success', "FIO API: importováno {$imported} převodů, přeskočeno {$skipped} duplicit.");
    }

    /**
     * Run FIO import for a single bank account from the scheduler (no HTTP context).
     * Returns ['imported' => int, 'skipped' => int] or throws RuntimeException on error.
     */
    public function runFioImportForScheduler(int $bankAccountId): array
    {
        $account = BankAccount::find($bankAccountId);
        if (!$account) {
            throw new \RuntimeException("Bank account #{$bankAccountId} not found.");
        }

        $token = Setting::get('fio_api_token_bank_account_' . $bankAccountId);
        if (empty($token)) {
            throw new \RuntimeException("No FIO API token configured for bank account #{$bankAccountId}.");
        }

        $fio    = app(FioApiService::class);
        $parser = app(FioCsvParser::class);

        // Mirror Kohana: set bookmark to last known transaction_code before fetchLast
        $lastId = BankTransfer::whereHas('bankStatement', function ($q) use ($bankAccountId) {
                $q->where('bank_account_id', $bankAccountId);
            })
            ->whereNotNull('transaction_code')
            ->max('transaction_code');

        if ($lastId) {
            $fio->setLastId($token, (int) $lastId);
        }

        $csvContent = $fio->fetchLast($token);
        $parsed     = $parser->parse($csvContent);

        if (empty($parsed['rows'])) {
            return ['imported' => 0, 'skipped' => 0];
        }

        [$imported, $skipped] = $this->persistParsed($account, $parsed['header'], $parsed['rows'], 'FIO API scheduler');

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * Shared import logic: create BankStatement + BankTransfer + Transfer rows from parsed CSV data.
     * Mirrors Kohana's Fio_Bank_Statement_File_Importer::store() double-entry accounting logic.
     * Returns [imported, skipped].
     */
    private function persistParsed(BankAccount $account, array $header, array $rows, string $type): array
    {
        $imported   = 0;
        $skipped    = 0;
        $postImport = []; // collected after transaction: [bankTransferId, memberId, amount]

        DB::transaction(function () use ($account, $header, $rows, $type, &$imported, &$skipped, &$postImport) {
            $stmt = new BankStatement();
            $stmt->bank_account_id = $account->id;
            $stmt->user_id         = auth()->id() ?? 1;
            $stmt->type            = $type;
            $stmt->from            = $header['dateStart'] ?? null;
            $stmt->to              = $header['dateEnd']   ?? null;
            $stmt->opening_balance = $header['openingBalance'] ?? 0;
            $stmt->closing_balance = $header['closingBalance'] ?? 0;
            $stmt->save();

            // Resolve system accounting accounts (Kohana Account_attribute_Model constants)
            $bankAcctId      = $this->getLinkedAccountId($account->id, 221000); // BANK (per-BA)
            $bankInterestsId = $this->getLinkedAccountId($account->id, 644000); // BANK_INTERESTS (per-BA)
            $memberFeesId    = DB::table('accounts')->where('account_attribute_id', 684000)->value('id'); // MEMBER_FEES
            $suppliersId     = DB::table('accounts')->where('account_attribute_id', 321000)->value('id'); // SUPPLIERS
            $cashId          = DB::table('accounts')->where('account_attribute_id', 211000)->value('id'); // CASH (Pokladna)

            if (!$bankAcctId) {
                \Illuminate\Support\Facades\Log::error(
                    "ImportController: bank account id={$account->id} has no linked accounting account (accounts_bank_accounts pivot missing). Double-entry transfers will be skipped."
                );
            }

            $userId  = auth()->id() ?? 1;
            $now     = now()->format('Y-m-d H:i:s');

            foreach ($rows as $row) {
                $transactionCode = isset($row['id_pohybu']) && $row['id_pohybu'] !== ''
                    ? (int) $row['id_pohybu']
                    : null;

                if ($transactionCode !== null) {
                    if (BankTransfer::where('transaction_code', $transactionCode)->exists()) {
                        $skipped++;
                        continue;
                    }
                }

                $amount     = (float)($row['castka'] ?? 0.0);
                $isIncoming = $amount >= 0;
                $absAmount  = abs($amount);
                $datetime   = $row['datum'] ?? $now;
                $text       = $row['zprava'] ?? ($row['identifikace'] ?? null);

                $bt = new BankTransfer();
                $bt->bank_statement_id = $stmt->id;
                $bt->transaction_code  = $transactionCode;
                $bt->variable_symbol   = isset($row['vs']) && $row['vs'] !== '' ? (int) $row['vs'] : null;
                $bt->constant_symbol   = $row['ks'] ?? null;
                $bt->specific_symbol   = $row['ss'] ?? null;
                $bt->comment           = isset($row['identifikace']) && $row['identifikace'] !== ''
                    ? $row['identifikace']
                    : ($row['zprava'] ?? null);

                $matchedMemberId  = null;
                $matchedCreditId  = null; // member's credit account id (221100)

                if (!$isIncoming) {
                    // Outgoing: bank → suppliers (expenses / outgoing payments)
                    $bt->origin_id      = $account->id;
                    $bt->destination_id = null;

                    if ($bankAcctId && $suppliersId) {
                        $primaryTransferId = $this->createTransfer(
                            $bankAcctId, $suppliersId, null, null, $userId, null,
                            $datetime, $now, $text, $absAmount
                        );
                        $bt->transfer_id = $primaryTransferId;
                    }

                } elseif (($row['typ'] ?? '') === 'Připsaný úrok') {
                    // Interest: bank_interests → bank
                    $bt->origin_id      = null;
                    $bt->destination_id = $account->id;

                    if ($bankInterestsId && $bankAcctId) {
                        $primaryTransferId = $this->createTransfer(
                            $bankInterestsId, $bankAcctId, null, null, $userId, null,
                            $datetime, $now, $row['typ'], $absAmount
                        );
                        $bt->transfer_id = $primaryTransferId;
                    }

                } else {
                    // Incoming (regular or cash deposit)
                    $bt->destination_id = $account->id;

                    // Kohana pattern: findOrCreate counterpart bank_account by account_nr + bank_nr
                    if (!empty($row['protiucet']) && !empty($row['kod_banky'])) {
                        $counterpart = BankAccount::firstOrCreate(
                            [
                                'account_nr' => $row['protiucet'],
                                'bank_nr'    => $row['kod_banky'],
                            ],
                            [
                                'name'      => $row['nazev_protiuctu'] ?? ($row['protiucet'] . '/' . $row['kod_banky']),
                                'member_id' => null,
                            ]
                        );
                        $bt->origin_id = $counterpart->id;
                    } else {
                        $bt->origin_id = null;
                    }

                    // 'Vklad pokladnou' uses the cash account as origin; all others use member_fees
                    $incomingOriginId = (($row['typ'] ?? '') === 'Vklad pokladnou') ? $cashId : $memberFeesId;

                    // Try to match member by variable symbol — string match je tu zásadní:
                    // sloupec variable_symbol je varchar (drží '12345' i '12345+U' u ukončených).
                    // Při int parametru by MariaDB castovala varchar→int a '12345+U' by matchla,
                    // takže platba ukončeného by se připsala na jeho účet místo do neidentifikovaných.
                    if ($bt->variable_symbol !== null) {
                        $vsModel = VariableSymbol::where('variable_symbol', (string) $bt->variable_symbol)->first();
                        if ($vsModel) {
                            // variable_symbols.account_id points directly to the 221100 credit account
                            $creditAcct = DB::table('accounts')
                                ->where('id', $vsModel->account_id)
                                ->where('account_attribute_id', 221100)
                                ->first();
                            if ($creditAcct) {
                                $memberAllowed = $this->filterMemberByBankAccount(
                                    $vsModel->account_id, $account, $bt
                                );
                                if ($memberAllowed) {
                                    $matchedMemberId = $creditAcct->member_id;
                                    $matchedCreditId = $creditAcct->id;
                                }
                            }
                        }
                    }

                    if (!$matchedMemberId && $bt->variable_symbol !== null) {
                        if (empty($bt->comment)) {
                            $bt->comment = 'Neidentifikovaný převod: VS=' . (string)$bt->variable_symbol;
                        }
                    }

                    if ($incomingOriginId && $bankAcctId) {
                        $primaryTransferId = $this->createTransfer(
                            $incomingOriginId, $bankAcctId, null, $matchedMemberId, $userId, null,
                            $datetime, $now, $text, $absAmount
                        );
                        $bt->transfer_id = $primaryTransferId;

                        // If member matched, create assigning transfer: bank → member credit
                        if ($matchedMemberId && $matchedCreditId) {
                            $this->createTransfer(
                                $bankAcctId, $matchedCreditId, $primaryTransferId, $matchedMemberId, $userId, null,
                                $datetime, $now, 'Přiřazení platby', $absAmount
                            );

                            $postImport[] = [
                                'memberId' => $matchedMemberId,
                                'amount'   => $absAmount,
                            ];
                        }
                    }
                }

                $bt->save();
                $imported++;

                // Outgoing transfers always belong to the organization (member_id=1)
                // so they don't appear in unidentified transfers list
                if (!$isIncoming && $bt->transfer_id) {
                    DB::table('transfers')
                        ->where('id', $bt->transfer_id)
                        ->update(['member_id' => 1]);
                }

                // Reconcile outgoing payments: match by OP #id in identifikace field (Kohana-compatible)
                if (!$isIncoming) {
                    $opId = null;
                    $identifikace = $row['identifikace'] ?? $row['zprava'] ?? '';
                    if ($identifikace !== '' && preg_match('~\bOP\s*#\s*([0-9]+)\b~i', $identifikace, $m)) {
                        $opId = (int) $m[1];
                    }

                    $match = null;
                    if ($opId) {
                        // Primary: match by OP id + bank_account + amount validation
                        $match = OutgoingPayment::where('id', $opId)
                            ->where('bank_account_id', $account->id)
                            ->whereIn('status', [
                                OutgoingPayment::STATUS_EXPORTED,
                                OutgoingPayment::STATUS_APPROVED,
                            ])
                            ->whereRaw('ABS(amount - ?) <= 0.01', [$absAmount])
                            ->first();
                    }

                    if ($match) {
                        $match->update([
                            'status'           => OutgoingPayment::STATUS_PAID,
                            'paid_at'          => now(),
                            'paid_transfer_id' => $bt->id,
                            'match_method'     => 'op_id',
                        ]);

                        // Update comment on original unidentified bank_transfer
                        if ($match->transfer_id) {
                            BankTransfer::where('transfer_id', $match->transfer_id)
                                ->update(['comment' => DB::raw(
                                    "CONCAT(COALESCE(comment, ''), ' | Vráceno – platba potvrzena')"
                                )]);

                            // Remove from unidentified list
                            DB::table('transfers')
                                ->where('id', $match->transfer_id)
                                ->update(['member_id' => 1]);
                        }
                    }
                }

                // Update postImport with actual bt->id
                if (!empty($postImport) && $matchedMemberId) {
                    $postImport[count($postImport) - 1]['bankTransferId'] = $bt->id;
                }
            }
        });

        // Run invoice/email generation outside the transaction (PDF is filesystem-based).
        // Selhání jedné post-akce (PDF generation, email queue, ...) nesmí zastavit
        // zbytek — bank_transfers už jsou zacommitované a další scheduler běh by je
        // znovu fetchLast nevrátil (max(transaction_code) se posunul). Log + pokračujeme.
        if (!empty($postImport)) {
            $invoiceService = new InvoiceService();
            foreach ($postImport as $job) {
                try {
                    $this->handlePostImport(
                        $invoiceService,
                        $job['bankTransferId'],
                        $job['memberId'],
                        $job['amount'],
                        $account
                    );
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error(
                        sprintf('handlePostImport failed for bank_transfer #%d (member #%d, amount %.2f): %s',
                            $job['bankTransferId'], $job['memberId'], $job['amount'], $e->getMessage()),
                        ['trace' => $e->getTraceAsString()]
                    );
                }
            }
        }

        return [$imported, $skipped];
    }

    /**
     * Generate invoice (services) or queue confirmation email (contribution)
     * after a bank transfer is successfully imported.
     */
    private function handlePostImport(
        InvoiceService $invoiceService,
        int $bankTransferId,
        int $memberId,
        float $amount,
        BankAccount $bankAccount
    ): void {
        if ($amount <= 0) return;

        if ((int)$bankAccount->payment_purpose === 1) {
            // Services account → generate invoice + PDF + email
            $invoiceService->generateServicesInvoice(
                $memberId,
                $amount,
                $bankAccount->id,
                [$bankTransferId]
            );
        } else {
            // Contribution account → queue confirmation email
            $invoiceService->queuePaymentConfirmation(
                $memberId,
                $amount,
                $bankAccount->name,
                [$bankTransferId]
            );
        }

        // Prepaid backcharge — dohnat měsíční poplatky, které DeductFees přeskočil
        // (payment_blocked=1) a pokud kredit teď stačí, odblokovat přístup. Volá se
        // po každé identifikované příchozí platbě. Try/catch ať selhání backcharge
        // nezahodí celý import — bank transfer už je zacommitnutý.
        try {
            $unblocked = app(\App\Services\PaymentBackchargeService::class)
                ->backchargeForMember($memberId, date('Y-m-d'));
            if ($unblocked) {
                app(\App\Services\PaymentBlockedRedirectService::class)
                    ->refreshForMember($memberId);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error(
                sprintf('PaymentBackcharge failed for member #%d (bank_transfer #%d): %s',
                    $memberId, $bankTransferId, $e->getMessage()),
                ['trace' => $e->getTraceAsString()]
            );
        }
    }

    /**
     * Equivalent of Kohana's pvfree_filter_member_by_bank_account.
     *
     * Checks whether the payment arrived on the expected bank account for the
     * member type that owns $accountId. If the account routing rules are
     * configured in the config table (bank_account_member_type_<type>) and the
     * payment arrived on the wrong bank account, the transfer stays unmatched
     * and a note is written to $bt->comment.
     *
     * Returns true if matching should proceed, false if it should be blocked.
     */
    private function filterMemberByBankAccount(int $accountId, BankAccount $importAccount, BankTransfer $bt): bool
    {
        // Resolve member type via account → member relation
        $account = \App\Models\Account::find($accountId);
        if (!$account || !$account->member_id) {
            return true; // can't determine type, allow
        }

        $member = Member::find($account->member_id);
        if (!$member) {
            return true;
        }

        // Blokovat párování pro čekající členy (typ 17/18) — smlouva/přihláška nepodepsána
        if (in_array((int) $member->type, [17, 18])) {
            $bt->comment = 'Čekající člen/zákazník — smlouva/přihláška nepodepsána, platba nespárována.';
            return false;
        }

        $memberType = (int) $member->type;
        $key        = sprintf(SettingController::KEY_BA_MEMBER_TYPE, $memberType);
        $expectedBaId = (int) Setting::get($key, 0);

        if ($expectedBaId === 0) {
            return true; // no rule configured for this type
        }

        if ($importAccount->id !== $expectedBaId) {
            $bt->comment = sprintf(
                'Platba patří %s (member_id=%d, typ=%d), ale přišla na špatný účet (%s). Očekáván účet id=%d.',
                $member->name,
                $member->id,
                $memberType,
                $importAccount->full_account_number,
                $expectedBaId
            );
            return false;
        }

        return true;
    }

    /**
     * Mirror of Kohana's Transfer_Model::insert_transfer().
     * Creates a transfer record and updates the account balances.
     */
    private function createTransfer(
        ?int $originId,
        ?int $destinationId,
        ?int $previousTransferId,
        ?int $memberId,
        ?int $userId,
        ?int $type,
        string $datetime,
        string $creationDatetime,
        ?string $text,
        float $amount
    ): int {
        $transferId = DB::table('transfers')->insertGetId([
            'origin_id'           => $originId,
            'destination_id'      => $destinationId,
            'previous_transfer_id'=> $previousTransferId,
            'member_id'           => $memberId,
            'user_id'             => $userId,
            'type'                => $type,
            'datetime'            => $datetime,
            'creation_datetime'   => $creationDatetime,
            'text'                => $text,
            'amount'              => $amount,
        ]);

        if ($originId) {
            DB::table('accounts')->where('id', $originId)->decrement('balance', $amount);
        }
        if ($destinationId) {
            DB::table('accounts')->where('id', $destinationId)->increment('balance', $amount);
        }

        return $transferId;
    }

    /**
     * Get the accounting account ID linked to a physical bank account via accounts_bank_accounts pivot.
     */
    private function getLinkedAccountId(int $bankAccountId, int $accountAttributeId): ?int
    {
        return DB::table('accounts_bank_accounts as aba')
            ->join('accounts as a', 'a.id', '=', 'aba.account_id')
            ->where('aba.bank_account_id', $bankAccountId)
            ->where('a.account_attribute_id', $accountAttributeId)
            ->value('a.id');
    }
}
