<?php
namespace App\Console\Commands;

use App\Models\Message;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RedirectFormerMembers extends Command
{
    protected $signature   = 'members:redirect-former {--force : Skip time check}';
    protected $description = 'Mark expired members as former, optionally remove devices, activate redirect message';

    const TYPE_FORMER = 15;

    public function handle(): int
    {
        // Step 1: Mark today's expired members as former (type=15, locked=1)
        $today = now()->format('Y-m-d');

        $updated = DB::table('members')
            ->where('type', '!=', self::TYPE_FORMER)
            ->whereNotNull('leaving_date')
            ->where('leaving_date', '!=', '0000-00-00')
            ->where('leaving_date', '<=', $today)
            ->update([
                'type'   => self::TYPE_FORMER,
                'locked' => 1,
            ]);

        if ($updated > 0) {
            $this->info("Marked {$updated} members as former (type=15).");
        }

        // Step 2: Remove devices if auto-remove enabled
        $autoRemove = (bool) \App\Models\Setting::get('former_member_auto_device_remove', 0);
        if ($autoRemove && $updated > 0) {
            // Find newly marked former members' devices
            $memberIds = DB::table('members')
                ->where('type', self::TYPE_FORMER)
                ->where('leaving_date', '<=', $today)
                ->where('leaving_date', '!=', '0000-00-00')
                ->pluck('id');

            foreach ($memberIds as $memberId) {
                $userIds = DB::table('users')->where('member_id', $memberId)->pluck('id');
                foreach ($userIds as $userId) {
                    $deviceIds = DB::table('devices')->where('user_id', $userId)->pluck('id');
                    foreach ($deviceIds as $deviceId) {
                        // Remove IP addresses via ifaces
                        $ifaceIds = DB::table('ifaces')->where('device_id', $deviceId)->pluck('id');
                        foreach ($ifaceIds as $ifaceId) {
                            DB::table('ip_addresses')->where('iface_id', $ifaceId)->delete();
                        }
                        DB::table('ifaces')->where('device_id', $deviceId)->delete();
                    }
                    DB::table('devices')->where('user_id', $userId)->delete();
                }
            }
            $this->info("Removed devices for former members (auto-remove enabled).");
        }

        // Step 3: Get FORMER_MEMBER_MESSAGE (type=19)
        $message = Message::where('type', 19)->first();
        if (!$message) {
            $this->warn('Former member message (type=19) not found.');
            return 1;
        }

        // Step 4: Get all former members with their IP addresses
        $formerMembers = DB::table('members as m')
            ->where('m.type', self::TYPE_FORMER)
            ->pluck('m.id');

        // Step 5: Clear old redirections for this message
        DB::table('messages_ip_addresses')
            ->where('message_id', $message->id)
            ->delete();

        // Step 6: Activate redirect for all former members' IPs
        $count = 0;
        $now   = now();
        foreach ($formerMembers as $memberId) {
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
                    'comment'       => 'Auto: bývalý člen',
                    'datetime'      => $now,
                ]);
                $count++;
            }
        }

        $this->info("Activated redirect for {$count} IP addresses of {$formerMembers->count()} former members.");
        return 0;
    }
}
