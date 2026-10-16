<?php
namespace App\Console\Commands;

use App\Domains\Notifications\Actions\RebuildAutoRedirectsAction;
use Illuminate\Console\Command;

/**
 * Hodinová synchronizace přesměrování pro flagnuté (payment_blocked=1).
 *
 * Safety net: kompletně přepočítat stav v messages_ip_addresses pro
 * DEBTOR_MESSAGE (5) i DEBTOR_MESSAGE_CLEN (25) — smaže staré, znovu
 * nasadí pro aktuální payment_blocked=1 členy podle jejich typu.
 */
class RedirectBlockedMembers extends Command
{
    protected $signature   = 'members:redirect-blocked {--force : Skip enabled check}';
    protected $description = 'Activate redirect (DEBTOR_MESSAGE/CLEN) for members with payment_blocked=1';

    public function handle(RebuildAutoRedirectsAction $rebuild): int
    {
        if (!$this->option('force') && !\App\Models\Setting::get('payment_blocked_redirect_enabled', 1)) {
            $this->info('Payment blocked redirect disabled, skipping.');
            return 0;
        }

        // Přestavba: smazat přesměrování obou dlužnických zpráv a založit znovu
        // pro aktuálně blokované (pro tyto zprávy je napojený jen flag, ne zůstatek).
        $result = $rebuild->debtors();
        if ($result === null) {
            $this->warn('DEBTOR_MESSAGE (5) ani DEBTOR_MESSAGE_CLEN (25) v messages tabulce — abort.');
            return 1;
        }

        [$found, $touched] = $result;
        $this->info("Found {$found} blocked members (payment_blocked=1).");
        $this->info("Rebuilt redirect for {$touched} members.");
        return 0;
    }
}
