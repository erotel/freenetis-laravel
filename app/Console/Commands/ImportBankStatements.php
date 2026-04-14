<?php

namespace App\Console\Commands;

use App\Http\Controllers\ImportController;
use App\Models\LogQueue;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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

    // Time_Activity_Rule type constants
    const TYPE_MONTHLY        = 1;
    const TYPE_WEEKLY         = 2;
    const TYPE_DAILY          = 3;
    const TYPE_DAILY_WD       = 4;
    const TYPE_HOURLY         = 5;
    const TYPE_AFTER_DEDUCTION = 6;

    public function handle(ImportController $importer): int
    {
        $now    = time();
        $onlyId = $this->option('account') ? (int) $this->option('account') : null;
        $force  = (bool) $this->option('force');

        $accounts = DB::table('bank_accounts')
            ->where('member_id', 1)
            ->get(['id', 'name', 'account_nr', 'bank_nr']);

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

            // Load rules from bank_accounts_automatical_downloads
            $rules = DB::table('bank_accounts_automatical_downloads')
                ->where('bank_account_id', $account->id)
                ->get();

            if (!$force) {
                if ($rules->isEmpty()) {
                    $this->line("  Skip #{$account->id} {$account->name}: no download rules configured.");
                    continue;
                }

                $matched = $this->matchRules($rules, $now);
                if (!$matched) {
                    $this->line("  Skip #{$account->id} {$account->name}: no rule matches current time.");
                    continue;
                }
            }

            $label = "#{$account->id} {$account->name} ({$account->account_nr}/{$account->bank_nr})";
            $this->info("Importing {$label}...");

            try {
                $result = $importer->runFioImportForScheduler($account->id);

                $imported = $result['imported'];
                $skipped  = $result['skipped'];
                $totalImported += $imported;
                $totalSkipped  += $skipped;

                $this->line("  OK: {$imported} imported, {$skipped} skipped.");

                if ($imported > 0) {
                    $msg = "Bank statement imported for {$label}: {$imported} new, {$skipped} duplicates skipped.";
                    DB::table('log_queues')->insert([
                        'type'        => LogQueue::TYPE_INFO,
                        'state'       => LogQueue::STATE_NEW,
                        'created_at'  => now(),
                        'description' => $msg,
                    ]);
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

    /**
     * Returns true if at least one rule in the collection matches the given unix timestamp.
     * Mirrors Time_Activity_Rule::filter_rules() with AM_BANK_STATEMENTS='55'.
     */
    private function matchRules(\Illuminate\Support\Collection $rules, int $time): bool
    {
        $hour      = (int) date('G', $time);   // 0-23
        $day       = (int) date('j', $time);   // 1-31
        $month     = (int) date('n', $time);   // 1-12
        $year      = (int) date('Y', $time);
        $dayOfWeek = (int) date('N', $time);   // 1=Mon … 7=Sun

        foreach ($rules as $rule) {
            $attrs = $rule->attribute !== null ? explode('/', $rule->attribute) : [];
            $attr0 = isset($attrs[0]) ? (int) $attrs[0] : 0; // primary attribute
            $attr1 = isset($attrs[1]) ? (int) $attrs[1] : 0; // secondary attribute (hour)

            $passed = match ((int) $rule->type) {
                self::TYPE_MONTHLY => (function () use ($day, $month, $year, $hour, $attr0, $attr1): bool {
                    $daysInMonth = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
                    $effectiveDay = max(1, min($daysInMonth, $attr0));
                    return $day === $effectiveDay && $hour === $attr1;
                })(),

                self::TYPE_WEEKLY =>
                    $dayOfWeek === $attr0 && $hour === $attr1,

                self::TYPE_DAILY =>
                    $hour === $attr0,

                self::TYPE_DAILY_WD =>
                    $hour === $attr0 && $dayOfWeek <= 5,

                self::TYPE_HOURLY =>
                    true,

                self::TYPE_AFTER_DEDUCTION => (function () use ($hour, $day, $month, $year, $attr0): bool {
                    if (!Setting::get('deduct_fees_automatically_enabled', 0)) return false;
                    $deductDay  = (int) Setting::get('deduct_day', 26);
                    $daysInMonth = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
                    $effectiveDay = min($deductDay, $daysInMonth);
                    return $hour === $attr0 && $day === $effectiveDay;
                })(),

                default => false,
            };

            if ($passed) {
                return true;
            }
        }

        return false;
    }
}
