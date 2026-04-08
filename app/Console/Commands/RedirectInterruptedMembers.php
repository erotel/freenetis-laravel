<?php
namespace App\Console\Commands;

use App\Models\Message;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RedirectInterruptedMembers extends Command
{
    protected $signature   = 'members:redirect-interrupted {--force : Skip enabled check}';
    protected $description = 'Activate redirect message for members with interrupted membership';

    public function handle(): int
    {
        // Check if membership interrupts are enabled
        if (!$this->option('force')) {
            if (!\App\Models\Setting::get('membership_interrupt_enabled', 0)) {
                $this->info('Membership interrupts disabled, skipping.');
                return 0;
            }
        }

        // Get INTERRUPTED_MEMBERSHIP_MESSAGE (type=4)
        $message = Message::where('type', Message::INTERRUPTED_MEMBERSHIP_MESSAGE)->first();
        if (!$message) {
            $this->warn('Interrupted membership message (type=4) not found.');
            return 1;
        }

        $today = now()->format('Y-m-d');

        // Find members with active membership interrupts today
        // membership_interrupts links via members_fees (activation_date/deactivation_date)
        $interruptedMemberIds = DB::table('membership_interrupts as mi')
            ->join('members_fees as mf', 'mf.id', '=', 'mi.members_fee_id')
            ->where('mf.activation_date', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('mf.deactivation_date')
                  ->orWhere('mf.deactivation_date', '>=', $today);
            })
            ->pluck('mi.member_id')
            ->unique();

        $this->info("Found {$interruptedMemberIds->count()} members with interrupted membership.");

        // Clear old redirections for this message
        DB::table('messages_ip_addresses')
            ->where('message_id', $message->id)
            ->delete();

        // Activate redirect for all interrupted members' IPs
        $count = 0;
        $now   = now();
        foreach ($interruptedMemberIds as $memberId) {
            $ipIds = DB::table('ip_addresses as ip')
                ->join('ifaces as i', 'i.id', '=', 'ip.iface_id')
                ->join('devices as d', 'd.id', '=', 'i.device_id')
                ->join('users as u', 'u.id', '=', 'd.user_id')
                ->where('u.member_id', $memberId)
                ->pluck('ip.id');

            foreach ($ipIds as $ipId) {
                DB::table('messages_ip_addresses')->insertOrIgnore([
                    'message_id'    => $message->id,
                    'ip_address_id' => $ipId,
                    'user_id'       => 1,
                    'comment'       => 'Auto: přerušené členství',
                    'datetime'      => $now,
                ]);
                $count++;
            }
        }

        $this->info("Activated redirect for {$count} IP addresses.");
        return 0;
    }
}
