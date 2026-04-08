<?php
namespace App\Console\Commands;

use App\Models\Message;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateAllowedSubnets extends Command
{
    protected $signature   = 'subnets:update-allowed {--force : Skip interval/enabled checks}';
    protected $description = 'Update allowed subnets redirections - activate message for members connecting from unallowed subnet';

    public function handle(): int
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
        $ipIds = $this->getIpAddressesWithUnallowedSubnet();

        $this->info("Found {$ipIds->count()} IP addresses on unallowed subnets.");

        // Clear old redirections for this message
        $deleted = DB::table('messages_ip_addresses')
            ->where('message_id', $message->id)
            ->delete();
        $this->info("Cleared {$deleted} old redirections.");

        // Insert new redirections
        $now = now();
        foreach ($ipIds as $ipId) {
            DB::table('messages_ip_addresses')->insertOrIgnore([
                'message_id'    => $message->id,
                'ip_address_id' => $ipId,
                'user_id'       => 1,
                'comment'       => 'Auto: nepovolená podsíť',
                'datetime'      => $now,
            ]);
        }

        $this->info("Activated redirections for {$ipIds->count()} IP addresses.");

        // Update last run timestamp
        Setting::set('allowed_subnets_update_state', now()->format('Y-m-d H:i:s'));

        return 0;
    }

    private function getIpAddressesWithUnallowedSubnet(): \Illuminate\Support\Collection
    {
        // Find IP addresses of members whose subnet is NOT in their allowed_subnets (enabled=1)
        return DB::table('ip_addresses as ip')
            ->join('ifaces as i', 'i.id', '=', 'ip.iface_id')
            ->join('devices as d', 'd.id', '=', 'i.device_id')
            ->join('users as u', 'u.id', '=', 'd.user_id')
            ->join('members as m', 'm.id', '=', 'u.member_id')
            ->where('m.id', '!=', 1)
            ->whereNotNull('ip.subnet_id')
            ->whereNotExists(function ($q) {
                $q->from('allowed_subnets as als')
                  ->whereColumn('als.member_id', 'm.id')
                  ->whereColumn('als.subnet_id', 'ip.subnet_id')
                  ->where('als.enabled', 1);
            })
            // Only members who have at least one allowed subnet configured
            ->whereExists(function ($q) {
                $q->from('allowed_subnets as als2')
                  ->whereColumn('als2.member_id', 'm.id');
            })
            ->pluck('ip.id')
            ->unique();
    }
}
