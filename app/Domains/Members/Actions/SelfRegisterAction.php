<?php

namespace App\Domains\Members\Actions;

use App\Domains\Billing\Repositories\LedgerRepository;
use App\Domains\Members\Repositories\MemberRepository;
use App\Domains\Members\Support\RegistrationSummaryMail;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Veřejná samoregistrace: adresa, člen (3 organizace s datem vstupu; 17/18
 * čekající bez data vstupu — doplní se až schválením), hlavní uživatel,
 * kreditní účet + VS, e-mail a telefon. Rychlost se nenastavuje (admin před
 * smlouvou). Audit bez uživatele (anonymní) + souhrn smlouvy e-mailem.
 */
class SelfRegisterAction
{
    public function __construct(
        private MemberRepository $members,
        private LedgerRepository $ledger,
        private RegistrationSummaryMail $summary,
    ) {
    }

    /** @param array $v validovaná data formuláře @return int id člena */
    public function handle(array $v): int
    {
        $type  = (int) $v['registration_type'];
        $isOrg = $type === 3;

        [$memberId, $userId] = DB::transaction(function () use ($v, $type, $isOrg) {
            $addressPointId = $this->members->createAddressPoint((int) $v['town_id'], (int) $v['street_id'], $v['street_number']);

            $fullName = mb_substr($isOrg ? $v['name'] : trim($v['name'] . ($v['surname'] ? ' ' . $v['surname'] : '')), 0, 100);
            $memberId = $this->members->create($fullName, $type, in_array($type, [17, 18], true) ? null : now()->format('Y-m-d'),
                $v['comment'] ?? '', $v['organization_identifier'] ?? '', $v['vat_organization_identifier'] ?? '', $addressPointId);

            // organizace: název rozdělený do name (30) / surname (60)
            $userId = $this->members->createMainUser($memberId, mb_substr($v['name'], 0, 30),
                $isOrg ? mb_substr(mb_substr($v['name'], 30), 0, 60) : mb_substr($v['surname'] ?? '', 0, 60),
                $v['login'], $v['password'], $v['birthday']);

            $this->ledger->openCreditAccount($memberId);
            $this->members->addContact($userId, 20, $v['email']);
            $this->members->addContact($userId, 21, $v['phone']);

            return [$memberId, $userId];
        });

        AuditLogger::log('created', 'members', $memberId, null, ['type' => $type, 'login' => $v['login'], 'via' => 'veřejná registrace']);
        $this->summary->send($userId, $type);

        return $memberId;
    }
}
