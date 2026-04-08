<?php
namespace App\Console\Commands;

use App\Models\Message;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RedirectExpiredApplicants extends Command
{
    protected $signature   = 'members:redirect-expired-applicants {--force : Skip enabled checks}';
    protected $description = 'Activate redirect for applicants whose connection test period has expired';

    const TYPE_APPLICANT = 1;

    public function handle(): int
    {
        // Check self_registration enabled
        if (!$this->option('force')) {
            if (!Setting::get('self_registration', 0)) {
                $this->info('Self-registration disabled, skipping.');
                return 0;
            }
        }

        // Check test duration configured
        $duration = (int) Setting::get('applicant_connection_test_duration', 0);
        if ($duration <= 0) {
            $this->info('applicant_connection_test_duration not set or 0, skipping.');
            return 0;
        }

        // Get CONNECTION_TEST_EXPIRED message (type=16)
        $message = Message::where('type', 16)->first();
        if (!$message) {
            $this->warn('Message type CONNECTION_TEST_EXPIRED (16) not found.');
            return 1;
        }

        // Find IP addresses of applicants whose test has expired
        // members.type = 1 (applicant), registration = 0 (completed),
        // DATEDIFF(NOW(), applicant_connected_from) > duration
        $ipIds = DB::table('ip_addresses as ip')
            ->join('ifaces as i', 'i.id', '=', 'ip.iface_id')
            ->join('devices as d', 'd.id', '=', 'i.device_id')
            ->join('users as u', 'u.id', '=', 'd.user_id')
            ->join('members as m', 'm.id', '=', 'u.member_id')
            ->where('m.type', self::TYPE_APPLICANT)
            ->where('m.registration', 0)
            ->whereNotNull('m.applicant_connected_from')
            ->where('m.applicant_connected_from', '!=', '0000-00-00')
            ->whereRaw('DATEDIFF(NOW(), m.applicant_connected_from) > ?', [$duration])
            ->pluck('ip.id')
            ->unique();

        $this->info("Found {$ipIds->count()} IP addresses of expired applicants.");

        // Clear old redirections for this message
        DB::table('messages_ip_addresses')
            ->where('message_id', $message->id)
            ->delete();

        // Activate redirect
        $now = now();
        foreach ($ipIds as $ipId) {
            DB::table('messages_ip_addresses')->insertOrIgnore([
                'message_id'    => $message->id,
                'ip_address_id' => $ipId,
                'user_id'       => 1,
                'comment'       => 'Auto: vypršelo testovací připojení',
                'datetime'      => $now,
            ]);
        }

        $this->info("Done.");
        return 0;
    }
}
