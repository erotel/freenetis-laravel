<?php
namespace App\Console\Commands;

use App\Domains\Notifications\Repositories\RedirectionRepository;
use App\Models\Message;
use App\Models\Setting;
use Illuminate\Console\Command;

class UpdateAllowedSubnets extends Command
{
    protected $signature   = 'subnets:update-allowed {--force : Skip interval/enabled checks}';
    protected $description = 'Update allowed subnets redirections - activate message for members connecting from unallowed subnet';

    public function handle(RedirectionRepository $redirects): int
    {
        // Check if enabled
        if (!$this->option('force')) {
            if (!Setting::get('allowed_subnets_enabled', 0)) {
                $this->info('Allowed subnets check disabled, skipping.');
                return 0;
            }

            // Check interval
            $lastUpdate = Setting::get('allowed_subnets_update_state', '2000-01-01 00:00:00');
            $interval   = (int) Setting::get('allowed_subnets_update_interval', 3600);
            if ((strtotime($lastUpdate) + $interval) >= time()) {
                $this->info('Interval not elapsed, skipping.');
                return 0;
            }
        }

        // Get UNALLOWED_CONNECTING_PLACE message (type=7)
        $message = Message::where('type', Message::UNALLOWED_CONNECTING_PLACE)->first();
        if (!$message) {
            $this->warn('Message type UNALLOWED_CONNECTING_PLACE (7) not found.');
            return 1;
        }

        // Get IP addresses connecting from unallowed subnets
        $ipIds = $redirects->ipsOutsideAllowedSubnets();

        $this->info("Found {$ipIds->count()} IP addresses on unallowed subnets.");

        // Clear old redirections for this message
        $deleted = $redirects->clear([(int) $message->id]);
        $this->info("Cleared {$deleted} old redirections.");

        // Insert new redirections
        $redirects->addAuto((int) $message->id, $ipIds, 1, 'Auto: nepovolená podsíť');

        $this->info("Activated redirections for {$ipIds->count()} IP addresses.");

        // Update last run timestamp
        Setting::set('allowed_subnets_update_state', now()->format('Y-m-d H:i:s'));

        return 0;
    }
}
