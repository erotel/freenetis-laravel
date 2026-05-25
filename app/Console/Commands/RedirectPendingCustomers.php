<?php
namespace App\Console\Commands;

use App\Helpers\MemberType;
use App\Models\Message;
use App\Services\PendingCustomerRedirectService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RedirectPendingCustomers extends Command
{
    protected $signature   = 'members:redirect-pending-customers {--force : Skip enabled check}';
    protected $description = 'Activate redirect message for pending customers (type=18, unsigned contract)';

    public function handle(PendingCustomerRedirectService $service): int
    {
        if (!$this->option('force')) {
            if (!\App\Models\Setting::get('pending_customer_redirect_enabled', 1)) {
                $this->info('Pending customer redirect disabled, skipping.');
                return 0;
            }
        }

        $message = Message::where('type', Message::PENDING_CUSTOMER_MESSAGE)->first();
        if (!$message) {
            $this->warn('Pending customer message (type=32) not found.');
            return 1;
        }

        // Safety net: kompletně přepočítat stav, kdyby controller hook někde selhal
        // (race, výjimka před commitnutím, ...). Smaž všechny záznamy pro tuto
        // message a znovu založ jen pro aktuální type=18 členy.
        DB::table('messages_ip_addresses')
            ->where('message_id', $message->id)
            ->delete();

        $pendingMemberIds = DB::table('members')
            ->where('type', MemberType::PENDING_CUSTOMER)
            ->pluck('id');

        $this->info("Found {$pendingMemberIds->count()} pending customers (type=18).");

        $touched = 0;
        foreach ($pendingMemberIds as $memberId) {
            if ($service->refreshForMember((int) $memberId)) {
                $touched++;
            }
        }

        $this->info("Rebuilt redirect for {$touched} members.");
        return 0;
    }
}
