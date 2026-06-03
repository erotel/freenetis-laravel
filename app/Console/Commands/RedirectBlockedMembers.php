<?php
namespace App\Console\Commands;

use App\Models\Message;
use App\Services\PaymentBlockedRedirectService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RedirectBlockedMembers extends Command
{
    protected $signature   = 'members:redirect-blocked {--force : Skip enabled check}';
    protected $description = 'Activate redirect message for members with insufficient credit (payment_blocked=1)';

    public function handle(PaymentBlockedRedirectService $service): int
    {
        if (!$this->option('force')) {
            if (!\App\Models\Setting::get('payment_blocked_redirect_enabled', 1)) {
                $this->info('Payment blocked redirect disabled, skipping.');
                return 0;
            }
        }

        $message = Message::where('type', Message::PAYMENT_BLOCKED_MESSAGE)->first();
        if (!$message) {
            $this->warn('Payment blocked message (type=33) not found.');
            return 1;
        }

        // Safety net (stejně jako redirect-pending-customers): kompletně přepočíst
        // stav, kdyby controller/service hook někde selhal (race, výjimka před
        // commitnutím). Smaž všechny záznamy pro tuto message a znovu založ jen
        // pro aktuální payment_blocked=1 členy.
        DB::table('messages_ip_addresses')
            ->where('message_id', $message->id)
            ->delete();

        $blockedMemberIds = DB::table('members')
            ->where('payment_blocked', 1)
            ->pluck('id');

        $this->info("Found {$blockedMemberIds->count()} blocked members (payment_blocked=1).");

        $touched = 0;
        foreach ($blockedMemberIds as $memberId) {
            if ($service->refreshForMember((int) $memberId)) {
                $touched++;
            }
        }

        $this->info("Rebuilt redirect for {$touched} members.");
        return 0;
    }
}
