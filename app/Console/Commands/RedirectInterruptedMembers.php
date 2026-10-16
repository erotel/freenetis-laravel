<?php
namespace App\Console\Commands;

use App\Domains\Notifications\Actions\RebuildAutoRedirectsAction;
use Illuminate\Console\Command;

class RedirectInterruptedMembers extends Command
{
    protected $signature   = 'members:redirect-interrupted {--force : Skip enabled check}';
    protected $description = 'Activate redirect message for members with interrupted membership';

    public function handle(RebuildAutoRedirectsAction $rebuild): int
    {
        if (!$this->option('force') && !\App\Models\Setting::get('membership_interrupt_enabled', 0)) {
            $this->info('Membership interrupts disabled, skipping.');
            return 0;
        }

        $result = $rebuild->interrupted();
        if ($result === null) {
            $this->warn('Interrupted membership message (type=4) not found.');
            return 1;
        }

        [$members, $ips] = $result;
        $this->info("Found {$members} members with interrupted membership.");
        $this->info("Activated redirect for {$ips} IP addresses.");
        return 0;
    }
}
