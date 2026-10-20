<?php

namespace App\Domains\Members\Actions;

use App\Domains\Audit\AuditLogger;
use App\Domains\Billing\Repositories\LedgerRepository;
use App\Domains\Members\Repositories\MemberRepository;
use App\Domains\Members\Support\NewMember;
use App\Helpers\MemberType;
use Illuminate\Support\Facades\DB;

/**
 * Založení člena adminem (formulář Nový člen): adresní bod, člen, hlavní
 * uživatel, kreditní účet s VS, výchozí rychlost (ne řádnému členovi — ten
 * tarif mít nesmí), kontakty e-mail/telefon; audit. Oprávnění kontroluje volající.
 */
class RegisterMemberAction
{
    public function __construct(
        private MemberRepository $members,
        private LedgerRepository $ledger,
    ) {
    }

    /** @return int id nového člena */
    public function handle(NewMember $new): int
    {
        $memberId = DB::transaction(function () use ($new) {
            $addressPointId = $this->members->createAddressPoint($new->townId, $new->streetId, $new->streetNumber);
            $memberId = $this->members->create($new->fullName(), $new->type, $new->entranceDate, $new->comment,
                $new->organizationIdentifier, $new->vatOrganizationIdentifier, $addressPointId);
            $userId = $this->members->createMainUser($memberId, $new->name, $new->surname, $new->login, $new->password, $new->birthday);

            $this->ledger->openCreditAccount($memberId);

            if ((int) $new->type !== MemberType::REGULAR) {
                $this->members->setSpeedClass($memberId, $this->members->defaultSpeedClassId());
            }

            if ($new->email !== null) {
                $this->members->addContact($userId, 20, $new->email);
            }
            if ($new->phone !== null) {
                $this->members->addContact($userId, 21, $new->phone);
            }

            return $memberId;
        });

        AuditLogger::log('created', 'members', $memberId, null, [
            'name'          => $new->fullName(),
            'type'          => $new->type,
            'entrance_date' => $new->entranceDate,
            'login'         => $new->login,
        ]);

        return $memberId;
    }
}
