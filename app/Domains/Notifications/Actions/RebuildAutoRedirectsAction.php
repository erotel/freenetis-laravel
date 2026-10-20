<?php

namespace App\Domains\Notifications\Actions;

use App\Domains\Network\Support\IpOwner;
use App\Domains\Notifications\Repositories\RedirectionRepository;
use App\Domains\Notifications\Support\AutoMessages;
use App\Helpers\MemberType;
use App\Models\Message;
use Illuminate\Support\Facades\DB;

/**
 * Přestavba automatických přesměrování (hodinové / denní crony): smazat vše
 * pro zprávu a založit znovu pro aktuální množinu — pojistka, kdyby hook při
 * změně stavu člena selhal. Vrací počet, nebo null, když systémová zpráva chybí.
 */
class RebuildAutoRedirectsAction
{
    public function __construct(
        private RedirectionRepository $redirects,
        private SyncDebtorRedirectAction $debtor,
        private SyncPendingCustomerRedirectAction $pending,
    ) {
    }

    /** Neplatiči (payment_blocked) zákazníci i členové. @return ?array{0:int,1:int} [blokovaných, přestavěno] */
    public function debtors(): ?array
    {
        $ids = AutoMessages::debtorIds();
        if (!$ids) return null;
        $this->redirects->clear($ids);

        $members = DB::table('members')->where('payment_blocked', 1)
            ->whereIn('type', [MemberType::CUSTOMER, MemberType::PENDING_CUSTOMER, MemberType::REGULAR, 3])->pluck('id');
        $touched = $members->filter(fn ($id) => $this->debtor->handle((int) $id))->count();
        return [$members->count(), $touched];
    }

    /** Čekající zákazníci (18). @return ?array{0:int,1:int} */
    public function pendingCustomers(): ?array
    {
        $msgId = AutoMessages::idOf(Message::PENDING_CUSTOMER_MESSAGE);
        if (!$msgId) return null;
        $this->redirects->clear([$msgId]);

        $members = DB::table('members')->where('type', MemberType::PENDING_CUSTOMER)->pluck('id');
        $touched = $members->filter(fn ($id) => $this->pending->handle((int) $id))->count();
        return [$members->count(), $touched];
    }

    /** Přerušení aktivní dnes (zpráva 4). @return ?array{0:int,1:int} [členů, IP] */
    public function interrupted(): ?array
    {
        $msgId = AutoMessages::idOf(Message::INTERRUPTED_MEMBERSHIP_MESSAGE);
        if (!$msgId) return null;

        $today = now()->format('Y-m-d');
        $members = DB::table('membership_interrupts as mi')
            ->join('members_fees as mf', 'mf.id', '=', 'mi.members_fee_id')
            ->where('mf.activation_date', '<=', $today)
            ->where(fn ($q) => $q->whereNull('mf.deactivation_date')->orWhere('mf.deactivation_date', '>=', $today))
            ->pluck('mi.member_id')->unique();

        $this->redirects->clear([$msgId]);
        $count = 0;
        foreach ($members as $memberId) {
            $ipIds = $this->redirects->memberIpIds((int) $memberId);
            $this->redirects->addAuto($msgId, $ipIds, 1, 'Auto: přerušené členství');
            $count += $ipIds->count();
        }
        return [$members->count(), $count];
    }

    /** Žadatelé (1) s nepodepsanou registrací, jejichž testovací připojení trvá déle než $days (zpráva typu 16). @return ?int IP */
    public function expiredApplicants(int $days): ?int
    {
        $msgId = AutoMessages::idOf(16); // CONNECTION_TEST_EXPIRED
        if (!$msgId) return null;

        $ipIds = IpOwner::query()
            ->join('members as m', 'm.id', '=', DB::raw(IpOwner::SQL))
            ->where('m.type', MemberType::APPLICANT)
            ->where('m.registration', 0)
            ->whereNotNull('m.applicant_connected_from')
            ->where('m.applicant_connected_from', '!=', '0000-00-00')
            ->whereRaw('DATEDIFF(NOW(), m.applicant_connected_from) > ?', [$days])
            ->pluck('ip.id')->unique();

        $this->redirects->clear([$msgId]);
        $this->redirects->addAuto($msgId, $ipIds, 1, 'Auto: vypršelo testovací připojení');
        return $ipIds->count();
    }
}
