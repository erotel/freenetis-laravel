<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Migrační čistič bilancí: pro stávající flagnuté členy (payment_blocked=1)
 * SMAZÁVÁ historické srážky, které proběhly od payment_blocked_since.
 *
 * Důvod: před přechodem na prepaid model DeductFees strhával nepodmíněně.
 * V čistém prepaid modelu by ty srážky nebyly (DeductFees by jen flagnul).
 * Smazání obnoví výchozí stav — bilance jde zpět na to, co bylo před stržením,
 * a getExpirationDate ("Zaplaceno do") sedí s payment_blocked_since.
 *
 * Smazat (ne kompenzovat counter-transferem), protože:
 *  - Pohoda za tyto srážky nevystavila faktury (ověřeno před spuštěním)
 *  - Counter-transfery by zmátly getExpirationDate (counter typ=1 with dest=credit
 *    nepočítá jako 'stržení', ale původní OUT transfer tam pořád zůstává a algoritmus
 *    ho považuje za 'naposledy strženo' → posune 'Zaplaceno do' o měsíc)
 *
 * Default = --dry-run.
 */
class ReverseBlockedDeductions extends Command
{
    protected $signature   = 'members:reverse-blocked-deductions
                                {--dry-run : Vypsat co by se stalo (default)}
                                {--apply : Provést změny}';
    protected $description = 'Smazat historické srážky u flagnutých členů — bilance se sníží zpět na pre-deduct stav (jednorázová migrační oprava)';

    const TYPE_MEMBER_FEE   = 1;
    const CREDIT_ACCOUNT    = 221100;
    const OPERATING_ACCOUNT = 221101;

    public function handle(): int
    {
        $dryRun = !$this->option('apply');

        if ($dryRun) {
            $this->warn('DRY RUN — žádné změny se nezapíšou. Pro provedení spusť s --apply.');
        }

        $orgAccount = DB::table('accounts')
            ->where('member_id', 1)
            ->where('account_attribute_id', self::OPERATING_ACCOUNT)
            ->value('id');
        if (!$orgAccount) {
            $this->error('Organization operating account (221101) not found, abort.');
            return 1;
        }

        $members = DB::table('members as m')
            ->join('accounts as a', function ($j) {
                $j->on('a.member_id', '=', 'm.id')->where('a.account_attribute_id', self::CREDIT_ACCOUNT);
            })
            ->where('m.payment_blocked', 1)
            ->whereNotNull('m.payment_blocked_since')
            ->select('m.id', 'm.name', 'm.payment_blocked_since', 'a.id AS account_id', 'a.balance')
            ->orderBy('m.id')
            ->get();

        if ($members->isEmpty()) {
            $this->info('Žádní flagnutí členové.');
            return 0;
        }

        $this->info("Flagnutí celkem: {$members->count()}");

        $totalReversed   = 0.0;
        $deductionsCount = 0;

        if (!$dryRun) DB::beginTransaction();
        try {
            foreach ($members as $m) {
                $deductions = DB::table('transfers')
                    ->where('origin_id', $m->account_id)
                    ->where('type', self::TYPE_MEMBER_FEE)
                    ->whereDate('datetime', '>=', $m->payment_blocked_since)
                    ->get(['id', 'datetime', 'amount']);

                if ($deductions->isEmpty()) continue;

                $sum = (float) $deductions->sum('amount');
                $totalReversed   += $sum;
                $deductionsCount += $deductions->count();

                if ($dryRun) {
                    $this->line(sprintf(
                        '  #%d %s | balance %.2f → %.2f (smazat %d srážek od %s, celkem %.2f Kč)',
                        $m->id, $m->name, (float) $m->balance, (float) $m->balance + $sum,
                        $deductions->count(), $m->payment_blocked_since, $sum
                    ));
                    continue;
                }

                DB::table('transfers')
                    ->whereIn('id', $deductions->pluck('id')->all())
                    ->delete();
                DB::table('accounts')->where('id', $m->account_id)->increment('balance', $sum);
            }

            if (!$dryRun) {
                // Operating: po smazání odchozích transferů z kreditních účtů do operating
                // se snižuje incoming na operating → bilance jde dolů o totalReversed.
                $this->recalculateBalance($orgAccount);
                DB::commit();
                $this->info("Smazáno {$deductionsCount} srážek, celkem {$totalReversed} Kč vráceno do bilance kreditních účtů.");
            } else {
                $this->info(sprintf('Celkem by se smazalo: %d srážek za %.2f Kč.', $deductionsCount, $totalReversed));
                $this->warn('Spusť znovu s --apply pro provedení.');
            }
        } catch (\Throwable $e) {
            if (!$dryRun) DB::rollBack();
            $this->error('Selhalo: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }

    private function recalculateBalance(int $accountId): void
    {
        $incoming = (float) DB::table('transfers')->where('destination_id', $accountId)->sum('amount');
        $outgoing = (float) DB::table('transfers')->where('origin_id', $accountId)->sum('amount');
        DB::table('accounts')->where('id', $accountId)->update(['balance' => $incoming - $outgoing]);
    }
}
