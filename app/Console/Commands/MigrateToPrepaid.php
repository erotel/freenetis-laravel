<?php

namespace App\Console\Commands;

use App\Services\PaymentBlockedRedirectService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Jednorázová migrace existujících dat na kreditový (prepaid) model.
 *
 * Bývalí členové (type 15, 16) s mínusovým kreditem → vynulovat
 * (transfer credit → operating). Argumentačně: dluh za už neexistující službu
 * není reálný — nikdo ho stejně nezaplatí, jen by zaspamoval reporty.
 *
 * Stávající členové (type 2, 90) s mínusovým kreditem → spočítat datum
 * vzniku mínusu (chronologicky projít transfery a najít první den, kdy
 * kumulativní bilance klesla pod 0) a nastavit payment_blocked_since
 * + payment_blocked=1. Pokud since v měsíci M-1 nebo dříve → také
 * pending_termination=1 (admin to pak schválí).
 *
 * Default = --dry-run. Bez explicitního --apply nic nezmění.
 */
class MigrateToPrepaid extends Command
{
    protected $signature   = 'members:migrate-to-prepaid
                                {--dry-run : Vypiš co by se stalo (default)}
                                {--apply : Provést změny}';
    protected $description = 'Jednorázová migrace na kreditový (prepaid) model — vynulovat dluhy bývalých, flagnout stávající';

    const CREDIT_ACCOUNT    = 221100;
    const OPERATING_ACCOUNT = 221101;
    const TYPE_MEMBER_FEE   = 1;

    public function handle(): int
    {
        $dryRun = !$this->option('apply');
        $today  = date('Y-m-d');
        $currentMonth = date('Y-m');

        if ($dryRun) {
            $this->warn('DRY RUN — žádné změny se nezapíšou. Pro provedení spusť s --apply.');
        }

        // ── Krok 1: bývalí v mínusu ────────────────────────────────────────
        $formerNegative = DB::select("
            SELECT m.id AS member_id, m.name, m.type, a.id AS account_id, a.balance
            FROM members m
            JOIN accounts a ON a.member_id = m.id AND a.account_attribute_id = ?
            WHERE m.type IN (15, 16) AND a.balance < 0
            ORDER BY m.id
        ", [self::CREDIT_ACCOUNT]);

        $formerCount = count($formerNegative);
        $formerSum   = array_sum(array_map(fn($r) => abs((float) $r->balance), $formerNegative));
        $this->info(sprintf(
            'Krok 1: %d bývalých členů (type 15/16) v mínusu, celkový dluh %.2f Kč.',
            $formerCount, $formerSum
        ));

        if ($formerCount > 0) {
            $orgAccount = DB::table('accounts')
                ->where('member_id', 1)
                ->where('account_attribute_id', self::OPERATING_ACCOUNT)
                ->value('id');
            if (!$orgAccount) {
                $this->error('Organization operating account (221101) not found, abort.');
                return 1;
            }

            if (!$dryRun) {
                $created = 0;
                DB::beginTransaction();
                try {
                    foreach ($formerNegative as $r) {
                        $amount = abs((float) $r->balance);
                        DB::table('transfers')->insert([
                            'origin_id'         => $r->account_id,
                            'destination_id'    => $orgAccount,
                            'type'              => self::TYPE_MEMBER_FEE,
                            'amount'            => $amount,
                            'datetime'          => $today,
                            'creation_datetime' => date('Y-m-d H:i:s'),
                            'text'              => 'Vynulování dluhu — migrace na kreditový model',
                            'member_id'         => null,
                            'user_id'           => null,
                        ]);
                        DB::table('accounts')->where('id', $r->account_id)->update(['balance' => 0]);
                        $created++;
                    }
                    // Operating: incoming amount - outgoing (operating bere amount, takže incoming - 0)
                    $this->recalculateBalance($orgAccount);
                    DB::commit();
                    $this->info("  → Vytvořeno {$created} transferů vynulování.");
                } catch (\Throwable $e) {
                    DB::rollBack();
                    $this->error('Krok 1 selhal: ' . $e->getMessage());
                    return 1;
                }
            } else {
                $this->line('  (dry-run, nic se nezapsalo)');
            }
        }

        // ── Krok 2: stávající v mínusu ──────────────────────────────────────
        $activeNegative = DB::select("
            SELECT m.id AS member_id, m.name, m.type, m.entrance_date, m.payment_blocked,
                   a.id AS account_id, a.balance
            FROM members m
            JOIN accounts a ON a.member_id = m.id AND a.account_attribute_id = ?
            WHERE m.type IN (2, 90)
              AND (m.leaving_date = '9999-12-31' OR m.leaving_date = '0000-00-00' OR m.leaving_date > ?)
              AND a.balance < 0
              AND m.payment_blocked = 0
            ORDER BY m.id
        ", [self::CREDIT_ACCOUNT, $today]);

        $activeCount     = count($activeNegative);
        $activeFlagged   = 0;
        $activeForTerm   = 0;
        $sampleSinces    = [];

        $this->info(sprintf(
            'Krok 2: %d stávajících aktivních členů (type 2/90) v mínusu, ještě nezflagovaných.',
            $activeCount
        ));

        if ($activeCount > 0) {
            foreach ($activeNegative as $r) {
                $since = $this->findFirstNegativeDate((int) $r->account_id, (string) $r->entrance_date);
                if (!$since) continue; // fallback selhal — přeskoč

                $isOld = substr($since, 0, 7) < $currentMonth;
                $sampleSinces[] = [
                    'id'    => $r->member_id,
                    'name'  => $r->name,
                    'bal'   => (float) $r->balance,
                    'since' => $since,
                    'old'   => $isOld,
                ];

                if (!$dryRun) {
                    DB::table('members')->where('id', $r->member_id)->update([
                        'payment_blocked'       => 1,
                        'payment_blocked_since' => $since,
                        'pending_termination'   => $isOld ? 1 : 0,
                    ]);
                }

                $activeFlagged++;
                if ($isOld) $activeForTerm++;
            }

            $this->info("  → Flagnuto {$activeFlagged} členů, z toho {$activeForTerm} okamžitě pending_termination=1 (since < {$currentMonth}).");

            // Ukázka prvních 5
            $this->info('  Ukázka (max 5):');
            foreach (array_slice($sampleSinces, 0, 5) as $s) {
                $this->line(sprintf(
                    '    #%d %s | balance %.2f | since=%s%s',
                    $s['id'], $s['name'], $s['bal'], $s['since'],
                    $s['old'] ? '  → pending_termination' : ''
                ));
            }

            if (!$dryRun) {
                $this->info('  Refresh redirect přesměrování...');
                $svc = app(PaymentBlockedRedirectService::class);
                foreach ($activeNegative as $r) {
                    try {
                        $svc->refreshForMember((int) $r->member_id);
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::error('refresh redirect failed', [
                            'member_id' => $r->member_id, 'error' => $e->getMessage(),
                        ]);
                    }
                }
                $this->info('  Redirect synchronizován.');
            } else {
                $this->line('  (dry-run, nic se nezapsalo)');
            }
        }

        if ($dryRun) {
            $this->warn('Spusť znovu s --apply pro provedení změn.');
        } else {
            $this->info('Migrace dokončena.');
        }
        return 0;
    }

    /**
     * Najde poslední moment, kdy bilance přestala být nezáporná a od té doby
     * je nepřetržitě v mínusu. Procházi transfery chronologicky a sčítá
     * incoming (destination=id) minus outgoing (origin=id). Pokaždé když
     * running >= 0, pozice "since" se resetuje. První následující pokles pod 0
     * je výsledný datum.
     *
     * Vrací null pokud balance vždy zůstávala >= 0 — pak fallback na entrance_date.
     */
    private function findFirstNegativeDate(int $accountId, string $entranceDate): ?string
    {
        $transfers = DB::table('transfers')
            ->where(function ($q) use ($accountId) {
                $q->where('origin_id', $accountId)->orWhere('destination_id', $accountId);
            })
            ->orderBy('datetime')
            ->orderBy('id')
            ->get(['datetime', 'amount', 'origin_id', 'destination_id']);

        $running    = 0.0;
        $sinceDate  = null;

        foreach ($transfers as $t) {
            if ((int) $t->destination_id === $accountId) {
                $running += (float) $t->amount;
            } elseif ((int) $t->origin_id === $accountId) {
                $running -= (float) $t->amount;
            }
            if ($running >= 0) {
                $sinceDate = null;          // bilance se vyhojila, "since" se ruší
            } elseif ($sinceDate === null) {
                $sinceDate = substr((string) $t->datetime, 0, 10);   // pokles pod 0 (zatím trvá)
            }
        }

        return $sinceDate ?: ($entranceDate ?: null);
    }

    private function recalculateBalance(int $accountId): void
    {
        $incoming = (float) DB::table('transfers')->where('destination_id', $accountId)->sum('amount');
        $outgoing = (float) DB::table('transfers')->where('origin_id', $accountId)->sum('amount');
        DB::table('accounts')->where('id', $accountId)->update(['balance' => $incoming - $outgoing]);
    }
}
