<?php
namespace App\Console\Commands;

use App\Domains\Notifications\Actions\RebuildAutoRedirectsAction;
use Illuminate\Console\Command;

class RedirectPendingCustomers extends Command
{
    protected $signature   = 'members:redirect-pending-customers {--force : Skip enabled check}';
    protected $description = 'Activate redirect message for pending customers (type=18, unsigned contract)';

    public function handle(RebuildAutoRedirectsAction $rebuild): int
    {
        if (!$this->option('force') && !\App\Models\Setting::get('pending_customer_redirect_enabled', 1)) {
            $this->info('Pending customer redirect disabled, skipping.');
            return 0;
        }

        // Pojistka, kdyby hook při změně stavu selhal: přestavět pro aktuální typ 18.
        $result = $rebuild->pendingCustomers();
        if ($result === null) {
            $this->warn('Pending customer message (type=32) not found.');
            return 1;
        }

        [$found, $touched] = $result;
        $this->info("Found {$found} pending customers (type=18).");
        $this->info("Rebuilt redirect for {$touched} members.");
        return 0;
    }
}
