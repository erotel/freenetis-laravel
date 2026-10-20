<?php

namespace App\Domains\Members\Actions;

use App\Domains\Members\Events\MemberApproved;
use App\Domains\Members\Repositories\MemberRepository;
use App\Domains\Members\Support\MemberChanges;
use App\Helpers\MemberType;
use App\Models\AddressPoint;
use App\Models\IpAddress;
use App\Models\Member;
use App\Models\MemberVotingLog;
use App\Domains\Contracts\Repositories\ContractRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Úprava člena (formulář Upravit). Jemná práva (qos_ceil, Schůze) zjistí
 * volající a předá je; tady se vynutí:
 *  - rychlost: jen s qos_ceil a bez podepsané smlouvy (tarif pak jen dodatkem,
 *    apply-on-sign); řádný člen (90) rychlost nemá nikdy
 *  - hlasovací právo (senior): jen s právem Schůzí, jen typ 90; změna → log
 * Čekatel (17/18) → aktivní typ = schválení: datum vstupu dnes (pokud ho admin
 * nezměnil), datum přijetí přihlášky, event {@see MemberApproved}.
 * Po uložení se DHCP podsítě IP člena označí ke znovuvygenerování.
 */
class UpdateMemberAction
{
    public function __construct(
        private MemberRepository $members,
        private ContractRepository $contracts,
    ) {
    }

    /** Podepsaná smlouva → tarif se mění jen dodatkem, ne přímou editací. */
    public function hasSignedContract(int $memberId): bool
    {
        $signed = $this->contracts->forMember($memberId);
        return $signed && $signed->status === 'signed';
    }

    /** @throws ModelNotFoundException */
    public function handle(int $id, MemberChanges $c, bool $mayEditQos, bool $mayEditVoting, ?int $byUserId): Member
    {
        $member = $this->members->findOrFail($id);

        if ($mayEditQos && $this->hasSignedContract($id)) {
            $mayEditQos = false;
        }

        $oldCanVote = (bool) $member->can_vote;
        // Senior jen pro řádné členy; jiný typ → vynutit 0 (i podvržené can_vote=1).
        $newCanVote = $mayEditVoting
            ? ($c->type === MemberType::REGULAR && $c->canVote)
            : (bool) $member->can_vote;

        // Datum vstupu = den schválení; admin může datum ve formuláři ručně změnit.
        $oldType    = (int) $member->type;
        $isApproval = in_array($oldType, [MemberType::PENDING_MEMBER, MemberType::PENDING_CUSTOMER], true)
            && MemberType::isActive($c->type);
        $entranceUnchanged = (string) $c->entranceDate === (string) ($member->entrance_date ?: null);
        $entranceDate = ($isApproval && $entranceUnchanged) ? now()->format('Y-m-d') : $c->entranceDate;

        if ($isApproval) {
            $member->entrance_form_accepted = now()->toDateString();   // není fillable
        }

        $member->update([
            'name'                        => $c->name,
            'type'                        => $c->typeInput,
            'entrance_date'               => $entranceDate,
            'leaving_date'                => $c->leavingDate ?: '9999-12-31',
            'comment'                     => $c->comment,
            'organization_identifier'     => $c->organizationIdentifier,
            'vat_organization_identifier' => $c->vatOrganizationIdentifier,
            'locked'                      => $c->locked,
            'registration'                => $c->registration,
            'speed_class_id'              => $c->type === MemberType::REGULAR
                ? null
                : ($mayEditQos ? $c->speedClassId : $member->speed_class_id),
            'can_vote'                    => $newCanVote,
            'notification_by_redirection' => $c->notificationByRedirection,
            'notification_by_email'       => $c->notificationByEmail,
            'notification_by_sms'         => $c->notificationBySms,
        ]);

        if ($mayEditVoting && $newCanVote !== $oldCanVote) {
            MemberVotingLog::create([
                'member_id'  => $member->id,
                'action'     => $newCanVote ? MemberVotingLog::ACTION_GRANTED : MemberVotingLog::ACTION_DEGRADED,
                'reason'     => 'Ruční změna v editaci člena',
                'user_id'    => $byUserId,
                'created_at' => now(),
            ]);
        }

        if ($c->townId !== null) {
            $this->saveAddress($member, $c);
        }

        IpAddress::where('member_id', $id)
            ->with('subnet')
            ->get()
            ->each(fn ($ip) => $ip->subnet?->setExpired());

        if ($isApproval) {
            MemberApproved::dispatch($member->id, $oldType, $c->type);
        }

        return $member;
    }

    /** Stávající adresní bod se upraví na místě, jinak vznikne nový. */
    private function saveAddress(Member $member, MemberChanges $c): void
    {
        $addressPoint = $member->addressPoint ?? new AddressPoint();
        $addressPoint->town_id       = $c->townId;
        $addressPoint->street_id     = $c->streetId;
        $addressPoint->street_number = $c->streetNumber;
        if (!$addressPoint->exists) {
            $addressPoint->country_id = 1;
        }
        $addressPoint->save();

        if ($member->address_point_id !== $addressPoint->id) {
            $member->address_point_id = $addressPoint->id;
            $member->save();
        }
    }
}
