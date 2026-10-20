<?php

namespace App\Domains\Members\Queries;

use App\Domains\Billing\Fees\FeePolicy;
use App\Domains\Members\Support\FieldPresenter;
use App\Models\AccountAttribute;
use App\Models\Contact;
use App\Models\Device;
use App\Models\Fee;
use App\Models\Member;
use App\Models\MemberFee;
use App\Models\Setting;
use App\Models\User;

/**
 * Karta člena ve Field Mode (read model): stav, VS, saldo + zaplaceno do,
 * aktivní poplatek a efektivní měsíční částka (shodně se srážkou: individuální
 * → tarif → základní), kontakty hlavního uživatele, adresa, zařízení.
 */
class FieldMemberCardQuery
{
    public function __construct(private FeePolicy $fees)
    {
    }

    /** @return ?array data pro view field.member (bez canAddNote), null = člen neexistuje */
    public function find(int $id, bool $withDevices): ?array
    {
        $member = Member::with(['users', 'accounts.variableSymbols', 'addressPoint.town', 'addressPoint.street'])->find($id);
        if (!$member) {
            return null;
        }

        $variableSymbols = $member->accounts->flatMap(fn ($a) => $a->variableSymbols)->pluck('variable_symbol')->unique()->values();
        $creditAccount   = $member->accounts->first(fn ($a) => $a->account_attribute_id === AccountAttribute::CREDIT_ATTRIBUTE_ID);
        $balance         = $creditAccount ? (float) $creditAccount->balance : null;

        $mainUser = $member->users->firstWhere('type', User::MAIN_USER);
        $contacts = $mainUser ? $mainUser->contacts()->with('enumType')->get() : collect();

        [$monthlyFee, $monthlyFeeSource] = $this->monthlyFee($member);

        return [
            'member'           => $member,
            'status'           => FieldPresenter::status((bool) $member->locked, $balance,
                (bool) ($member->payment_blocked ?? false), (bool) ($member->pending_termination ?? false)),
            'variableSymbols'  => $variableSymbols,
            'balance'          => $balance,
            'expirationDate'   => ($creditAccount ? app(\App\Domains\Billing\Fees\PaidUntil::class)->for($creditAccount) : null),
            'activeMemberFee'  => $this->activeMemberFee($member),
            'monthlyFee'       => $monthlyFee,
            'monthlyFeeSource' => $monthlyFeeSource,
            'phones'           => $contacts->where('type', Contact::TYPE_PHONE)->pluck('value')->filter()->values(),
            'emails'           => $contacts->where('type', Contact::TYPE_EMAIL)->pluck('value')->filter()->values(),
            'address'          => FieldPresenter::address($member->addressPoint?->street?->street,
                $member->addressPoint?->street_number, $member->addressPoint?->town?->town),
            'devices'          => $withDevices ? $this->devices($member) : collect(),
        ];
    }

    /** Aktivní pravidelný poplatek člena, jinak výchozí dle typu (neuložený MemberFee). */
    private function activeMemberFee(Member $member): ?MemberFee
    {
        $active = MemberFee::where('member_id', $member->id)->active()->with('fee')->first();
        if ($active) {
            return $active;
        }
        $defaultFeeId = (int) Setting::get('default_fee_member_type_' . $member->type, 0);
        if ($defaultFeeId && ($defaultFee = Fee::find($defaultFeeId))) {
            $active = new MemberFee();
            $active->setRelation('fee', $defaultFee);
        }
        return $active;
    }

    /** @return array{0: ?float, 1: ?string} */
    private function monthlyFee(Member $member): array
    {
        $individual = $this->fees->individualFee((int) $member->id, now()->toDateString());
        if ($individual !== null) {
            return [$individual, 'individuální'];
        }
        $tarif = $this->fees->tarifPrice((int) $member->id);
        if ($tarif !== null) {
            return [$tarif, 'tarif'];
        }
        $default = $this->fees->defaultFeeByType((int) $member->type);
        return $default !== null ? [$default, 'základní'] : [null, null];
    }

    /** Zařízení přes uživatele člena (devices.user_id), podle jména. */
    private function devices(Member $member)
    {
        $userIds = $member->users->pluck('id');
        return $userIds->isNotEmpty()
            ? Device::whereIn('user_id', $userIds)->with('ifaces.ipAddresses')->orderBy('name')->get()
            : collect();
    }
}
