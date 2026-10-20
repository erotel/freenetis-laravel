<?php

namespace App\Console\Commands;

use App\Domains\Members\Repositories\MemberRepository;
use App\Domains\Notifications\Repositories\EmailQueueRepository;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Označuje členy s nezaplaceným poplatkem z předchozího (nebo staršího) měsíce
 * jako kandidáty na ukončení smlouvy (per VOP — neplatí déle než 1 měsíc).
 *
 * Spouští se denně, ale práci dělá jen v Setting('pending_termination_day', 14).
 * Default = 14. den měsíce → pokud někdo nezaplatil v M-1, 14. dne v M ho cron
 * označí; admin pak ručně rozhodne o ukončení ve /members/pending-termination.
 *
 * UI ukončení (mode=1) zůstává manuální (rozhodnutí 2B).
 */
class MarkPendingTermination extends Command
{
    protected $signature   = 'members:mark-pending-termination {--force : Run regardless of day-of-month}';
    protected $description = 'Mark members for termination if they have unpaid fees from previous month or older';

    public function handle(MemberRepository $members): int
    {
        $today    = date('Y-m-d');
        $todayDay = (int) date('j');

        if (!$this->option('force')) {
            $markDay = (int) Setting::get('pending_termination_day', 14);
            if ($todayDay !== $markDay) {
                $this->info("Today is day {$todayDay}, pending_termination_day is {$markDay} — skipping.");
                return 0;
            }
        }

        $currentMonth = date('Y-m');

        // Kandidáti: payment_blocked=1, pending_termination ještě 0,
        // payment_blocked_since spadá do měsíce M-1 nebo dříve.
        $candidates = $members->pendingTerminationCandidates($currentMonth);

        if ($candidates->isEmpty()) {
            $this->info('Žádní kandidáti na ukončení (payment_blocked z předchozích měsíců).');
            return 0;
        }

        // Variabilní symboly v jednom dotazu, aby se v emailu hodily k jménu.
        $vsByMember = $members->creditVariableSymbols($candidates->pluck('id')->all());

        // Mark
        $members->markPendingTermination($candidates->pluck('id')->all());

        // Audit trail: automatické označení kandidátů na ukončení (systém, user_id NULL).
        \App\Domains\Audit\AuditLogger::log('auto_pending_termination', 'members', null, null, [
            'flagged'    => $candidates->count(),
            'member_ids' => $candidates->pluck('id')->all(),
        ]);

        $this->info("Označeno {$candidates->count()} členů jako pending_termination=1.");

        // Email adminovi se seznamem
        $this->notifyAdmin($candidates, $vsByMember, $today);

        return 0;
    }

    private function notifyAdmin($candidates, $vsByMember, string $today): void
    {
        $to = (string) Setting::get(
            'admin_notification_email',
            Setting::get('email_default_email', '')
        );
        if ($to === '') {
            Log::warning('MarkPendingTermination: žádný admin email — nikam neoznamuju.');
            return;
        }

        $from   = Setting::get('email_default_email', 'noreply@freenetis.org');
        $prefix = Setting::get('email_subject_prefix', '');
        $subject = ($prefix ? $prefix . ' :: ' : '')
            . "Kandidáti na ukončení smlouvy ({$today})";

        $rows = '';
        foreach ($candidates as $m) {
            $debt  = number_format(abs((float) $m->balance), 2, ',', ' ');
            $since = $m->payment_blocked_since;
            $vs    = $vsByMember[$m->id] ?? '—';
            $days  = (int) ((strtotime($today) - strtotime($since)) / 86400);
            $rows .= sprintf(
                "<tr><td>%d</td><td>%s</td><td>%s</td><td style=\"text-align:right\">%s Kč</td>"
                . "<td>%s</td><td style=\"text-align:right\">%d</td></tr>\n",
                $m->id,
                htmlspecialchars($m->name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($vs, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                $debt,
                $since,
                $days
            );
        }

        $body = <<<HTML
<p>Tito členové mají nezaplacený poplatek z předchozího měsíce nebo staršího a byli
označeni jako kandidáti na ukončení smlouvy (per VOP).</p>
<p>Schvalte/zamítněte ve FreenetIS: <a href="/freenetis/members/pending-termination">/members/pending-termination</a></p>
<table border="1" cellpadding="6" cellspacing="0">
<thead><tr><th>ID</th><th>Jméno</th><th>VS</th><th>Stav účtu</th><th>Blokováno od</th><th>Dní</th></tr></thead>
<tbody>
{$rows}</tbody>
</table>
HTML;

        app(EmailQueueRepository::class)->enqueue((string) $from, $to, $subject, $body);
    }
}
