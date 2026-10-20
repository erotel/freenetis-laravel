<?php

namespace App\Console\Commands;

use App\Domains\Audit\Repositories\LogQueueRepository;
use App\Domains\Billing\Actions\ImportNewFioPaymentsAction;
use App\Domains\Billing\Bank\DownloadSchedule;
use App\Domains\Billing\Repositories\BankAccountRepository;
use App\Models\LogQueue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Mirrors Kohana Scheduler_Controller::bank_statements_import().
 *
 * Rules are stored in bank_accounts_automatical_downloads (Kohana ORM pluralises).
 * type constants (Time_Activity_Rule):
 *   1 = MONTHLY  (attribute: day/hour)
 *   2 = WEEKLY   (attribute: day-of-week/hour, Mon=1…Sun=7)
 *   3 = DAILY    (attribute: hour)
 *   4 = DAILY_WD (attribute: hour, working days only)
 *   5 = HOURLY
 *   6 = AFTER_DEDUCTION (attribute: hour, runs on deduct-day)
 *
 * Scheduled at cron '55 * * * *' (every hour at :55, matching AM_BANK_STATEMENTS='55').
 */
class ImportBankStatements extends Command
{
    protected $signature   = 'bank:import-statements
                                {--account= : Import only a specific bank account ID}
                                {--force    : Skip rule matching, import all accounts with tokens}';
    protected $description = 'Auto-import FIO bank statements for association bank accounts per configured rules';

    public function handle(ImportNewFioPaymentsAction $importer, BankAccountRepository $accounts, DownloadSchedule $schedule, LogQueueRepository $log): int
    {
        $now    = time();
        $onlyId = $this->option('account') ? (int) $this->option('account') : null;
        $force  = (bool) $this->option('force');


        $totalImported = 0;
        $totalSkipped  = 0;
        $errors        = 0;

        foreach ($accounts->organizationAccounts() as $account) {
            if ($onlyId && $account->id !== $onlyId) {
                continue;
            }

            if ($accounts->fioToken((int) $account->id) === '') {
                $this->line("  Skip #{$account->id} {$account->name}: no API token.");
                continue;
            }

            // Load rules from bank_accounts_automatical_downloads
            $rules = $accounts->downloadRules((int) $account->id);

            if (!$force) {
                if ($rules->isEmpty()) {
                    $this->line("  Skip #{$account->id} {$account->name}: no download rules configured.");
                    continue;
                }

                $matched = $schedule->matches($rules, $now);
                if (!$matched) {
                    $this->line("  Skip #{$account->id} {$account->name}: no rule matches current time.");
                    continue;
                }
            }

            $label = "#{$account->id} {$account->name} ({$account->account_nr}/{$account->bank_nr})";
            $this->info("Importing {$label}...");

            try {
                $result = $importer->handle($account->id);

                $imported = $result['imported'];
                $skipped  = $result['skipped'];
                $totalImported += $imported;
                $totalSkipped  += $skipped;

                $this->line("  OK: {$imported} imported, {$skipped} skipped.");

                if ($imported > 0) {
                    $msg = "Bank statement imported for {$label}: {$imported} new, {$skipped} duplicates skipped.";
                    $log->record(LogQueue::TYPE_INFO, $msg);
                }
            } catch (\RuntimeException $e) {
                // FIO API rate limit (409) — too soon since last download, not a real error
                if (str_contains($e->getMessage(), 'Příliš brzy')) {
                    $this->warn("  Skip {$label}: FIO rate limit (too soon since last download).");
                    continue;
                }

                $errors++;
                $errMsg = "FIO import failed for {$label}: " . $e->getMessage();
                $this->error("  Error: " . $e->getMessage());
                Log::error($errMsg);

                $log->record(LogQueue::TYPE_ERROR, $errMsg, $e->getTraceAsString());
            } catch (\Throwable $e) {
                $errors++;
                $errMsg = "FIO import unexpected error for {$label}: " . $e->getMessage();
                $this->error("  Unexpected error: " . $e->getMessage());
                Log::error($errMsg, ['trace' => $e->getTraceAsString()]);

                $log->record(LogQueue::TYPE_ERROR, $errMsg, $e->getTraceAsString());
            }
        }

        $this->info("Done. Imported: {$totalImported}, skipped: {$totalSkipped}, errors: {$errors}.");
        return $errors > 0 ? 1 : 0;
    }
}
