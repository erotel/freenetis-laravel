<?php

namespace App\Console\Commands;

use App\Http\Controllers\ImportController;
use App\Models\LogQueue;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ImportBankStatements extends Command
{
    protected $signature   = 'bank:import-statements {--account= : Import only a specific bank account ID}';
    protected $description = 'Auto-import FIO bank statements for all association bank accounts with a configured API token';

    public function handle(ImportController $importer): int
    {
        // Get all association bank accounts (member_id = 1) that have a FIO API token configured
        $accounts = DB::table('bank_accounts')
            ->where('member_id', 1)
            ->get(['id', 'name', 'account_nr', 'bank_nr']);

        $onlyId = $this->option('account') ? (int) $this->option('account') : null;

        $totalImported = 0;
        $totalSkipped  = 0;
        $errors        = 0;

        foreach ($accounts as $account) {
            if ($onlyId && $account->id !== $onlyId) {
                continue;
            }

            $token = Setting::get('fio_api_token_bank_account_' . $account->id);
            if (empty($token)) {
                $this->line("  Skip #{$account->id} {$account->name}: no API token.");
                continue;
            }

            $label = "#{$account->id} {$account->name} ({$account->account_nr}/{$account->bank_nr})";
            $this->info("Importing {$label}...");

            try {
                $result = $importer->runFioImportForScheduler($account->id);

                $imported = $result['imported'];
                $skipped  = $result['skipped'];
                $totalImported += $imported;
                $totalSkipped  += $skipped;

                $msg = "Bank statement imported for {$label}: {$imported} new, {$skipped} duplicates skipped.";
                $this->line("  OK: {$imported} imported, {$skipped} skipped.");

                if ($imported > 0) {
                    DB::table('log_queues')->insert([
                        'type'        => LogQueue::TYPE_INFO,
                        'state'       => LogQueue::STATE_NEW,
                        'created_at'  => now(),
                        'description' => $msg,
                    ]);
                }
            } catch (\RuntimeException $e) {
                // FIO API rate limit (409) — not a real error, just too soon
                if (str_contains($e->getMessage(), 'Příliš brzy')) {
                    $this->warn("  Skip {$label}: FIO rate limit (too soon since last download).");
                    continue;
                }

                $errors++;
                $errMsg = "FIO import failed for {$label}: " . $e->getMessage();
                $this->error("  Error: " . $e->getMessage());
                Log::error($errMsg);

                DB::table('log_queues')->insert([
                    'type'                => LogQueue::TYPE_ERROR,
                    'state'               => LogQueue::STATE_NEW,
                    'created_at'          => now(),
                    'description'         => $errMsg,
                    'exception_backtrace' => $e->getTraceAsString(),
                ]);
            } catch (\Throwable $e) {
                $errors++;
                $errMsg = "FIO import unexpected error for {$label}: " . $e->getMessage();
                $this->error("  Unexpected error: " . $e->getMessage());
                Log::error($errMsg, ['trace' => $e->getTraceAsString()]);

                DB::table('log_queues')->insert([
                    'type'                => LogQueue::TYPE_ERROR,
                    'state'               => LogQueue::STATE_NEW,
                    'created_at'          => now(),
                    'description'         => $errMsg,
                    'exception_backtrace' => $e->getTraceAsString(),
                ]);
            }
        }

        $this->info("Done. Imported: {$totalImported}, skipped: {$totalSkipped}, errors: {$errors}.");
        return $errors > 0 ? 1 : 0;
    }
}
