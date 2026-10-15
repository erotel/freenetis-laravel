<?php
namespace App\Console\Commands;

use App\Domains\Billing\Actions\DeductFeesAction;
use App\Models\Setting;
use Illuminate\Console\Command;

/**
 * Cron vstup měsíční srážky (denně 00:03). Rozhoduje JEN zda a kdy běžet
 * (finance_enabled, deduct_fees_automatically_enabled, deduct_day); samotná
 * srážka je App\Domains\Billing\Actions\DeductFeesAction.
 */
class DeductFees extends Command
{
    protected $signature   = 'fees:deduct {--date= : Date YYYY-MM-DD, defaults to today} {--force : Skip enabled/day checks} {--member= : Jen tento člen (ladění/test)}';
    protected $description = 'Monthly fee deduction: member fees, entrance fees, device fees';

    public function handle(DeductFeesAction $deduct): int
    {
        // Check if finance/deduction enabled
        if (!$this->option('force')) {
            if (!Setting::get('finance_enabled', 0)) {
                $this->info('Finance disabled, skipping.');
                return 0;
            }
            if (!Setting::get('deduct_fees_automatically_enabled', 0)) {
                $this->info('Automatic fee deduction disabled, skipping.');
                return 0;
            }
        }

        // Determine date
        $date = $this->option('date') ?? date('Y-m-d');
        [, , $day] = explode('-', $date);

        // Check deduct_day — run only on configured day (or last day of month if configured day > days in month)
        if (!$this->option('force')) {
            $deductDay    = (int) Setting::get('deduct_day', 26);
            $lastDay      = (int) date('t', strtotime($date));
            $effectiveDay = min($deductDay, $lastDay);
            if ((int)$day !== $effectiveDay) {
                $this->info("Today is day {$day}, deduct_day is {$effectiveDay} — skipping.");
                return 0;
            }
        }

        $this->info("Running fee deduction for date {$date}...");

        $only = $this->option('member') ? (int) $this->option('member') : null;

        $deducted = $deduct->handle($date, $only, fn (string $level, string $message) => $this->{$level}($message));
        if ($deducted === null) {
            $this->error('Organization operating/infrastructure account not found!');
            return 1;
        }

        return 0;
    }
}
