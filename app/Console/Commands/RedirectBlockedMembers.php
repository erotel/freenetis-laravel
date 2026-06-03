<?php
namespace App\Console\Commands;

use App\Helpers\MemberType;
use App\Models\Message;
use App\Services\PaymentBlockedRedirectService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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

    public function handle(PaymentBlockedRedirectService $service): int
    {
        if (!$this->option('force')) {
            if (!\App\Models\Setting::get('payment_blocked_redirect_enabled', 1)) {
                $this->info('Payment blocked redirect disabled, skipping.');
                return 0;
            }
        }

        $messageIds = DB::table('messages')
            ->whereIn('type', [Message::DEBTOR_MESSAGE, Message::DEBTOR_MESSAGE_CLEN])
            ->pluck('id');

        if ($messageIds->isEmpty()) {
            $this->warn('DEBTOR_MESSAGE (5) ani DEBTOR_MESSAGE_CLEN (25) v messages tabulce — abort.');
            return 1;
        }

        // Wipe všech aktivních redirectů pro tyto zprávy — pak rebuild pro aktuální
        // flagnuté členy. Stejný pattern jako redirect-pending-customers; legacy
        // NotificationActivation pravidla mohou mezitím přidat své vlastní záznamy,
        // ale pro tyto zprávy je v PVfree nastavení napojené jen na flag (ne na
        // balance) — wipe by neměl odstranit nic potřebného.
        DB::table('messages_ip_addresses')
            ->whereIn('message_id', $messageIds)
            ->delete();

        $blockedMemberIds = DB::table('members')
            ->where('payment_blocked', 1)
            ->whereIn('type', [
                MemberType::CUSTOMER, MemberType::PENDING_CUSTOMER,
                MemberType::REGULAR, 3,
            ])
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
