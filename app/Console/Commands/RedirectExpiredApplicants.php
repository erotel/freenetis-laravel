<?php
namespace App\Console\Commands;

use App\Models\Setting;
use App\Domains\Notifications\Actions\RebuildAutoRedirectsAction;
use Illuminate\Console\Command;

class RedirectExpiredApplicants extends Command
{
    protected $signature   = 'members:redirect-expired-applicants {--force : Skip enabled checks}';
    protected $description = 'Activate redirect for applicants whose connection test period has expired';


    public function handle(RebuildAutoRedirectsAction $rebuild): int
    {
        if (!$this->option('force') && !Setting::get('self_registration', 0)) {
            $this->info('Self-registration disabled, skipping.');
            return 0;
        }

        $duration = (int) Setting::get('applicant_connection_test_duration', 0);
        if ($duration <= 0) {
            $this->info('applicant_connection_test_duration not set or 0, skipping.');
            return 0;
        }

        $ips = $rebuild->expiredApplicants($duration);
        if ($ips === null) {
            $this->warn('Message type CONNECTION_TEST_EXPIRED (16) not found.');
            return 1;
        }

        $this->info("Found {$ips} IP addresses of expired applicants.");
        $this->info("Done.");
        return 0;
    }
}
