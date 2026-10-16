<?php

namespace App\Domains\Contracts\Support;

use App\Domains\Billing\Fees\FeePolicy;
use App\Models\Device;
use App\Models\Member;
use App\Models\User;

/**
 * Snímek smluvní strany z živých dat člena (contract_parties bez contract_id):
 * jméno, adresa člena, místo připojení (adresa prvního zařízení s adresou,
 * jinak adresa člena), VS, tarif + sleva, dodatečné služby, datum narození
 * hlavního uživatele, telefon a e-mail.
 */
class PartySnapshot
{
    public function __construct(
        private TariffSnapshot $tariffs,
        private FeePolicy $fees,
    ) {
    }

    public function fromMember(Member $member): array
    {
        $mainUser = $member->users()->where('type', User::MAIN_USER)->first();
        [$phone, $email] = MemberContacts::of($mainUser);

        $ap      = $member->addressPoint;
        $address = self::address($ap);
        $service = $this->serviceAddress($member, $ap);

        $vs = $member->accounts->flatMap(fn ($a) => $a->variableSymbols)->first()?->variable_symbol ?? '';

        $tariff = $this->tariffs->current($member);

        // PDF má fallback IČO → datum narození; bez něj je buňka u fyzické osoby prázdná.
        $birthday = $mainUser?->birthday;
        if ($birthday instanceof \DateTimeInterface) {
            $birthday = $birthday->format('Y-m-d');
        }
        $birthday = (is_string($birthday) && $birthday !== '' && $birthday !== '0000-00-00') ? $birthday : null;

        // Dodatečné služby aktivní při vzniku smlouvy (řádky + celková cena ve smlouvě).
        $services = $this->fees->additionalServiceItems((int) $member->id, now()->toDateString());

        return [
            'full_name'                 => $member->name,
            'street'                    => $address['street'],
            'town'                      => $address['town'],
            'service_street'            => $service['street'],
            'service_town'              => $service['town'],
            'service_zip'               => $service['zip'],
            'service_full_address'      => $service['full'],
            'country'                   => 'CZ',
            'ico'                       => $member->organization_identifier,
            'dic'                       => $member->vat_organization_identifier,
            'birthday'                  => $birthday,
            'speed_name'                => $tariff['speed_name'],
            'variable_symbol'           => $vs,
            'price'                     => $tariff['price'],
            'price_after_discount'      => $tariff['price_after_discount'],
            'discount_until'            => $tariff['discount_until'],
            'additional_services_json'  => !empty($services) ? $services : null,
            'additional_services_total' => !empty($services) ? array_sum(array_column($services, 'fee')) : null,
            'phone'                     => $phone,
            'email'                     => $email,
        ];
    }

    /** Místo připojení = adresa prvního zařízení člena (nejnižší id) s adresou umístění, jinak $memberAp. */
    private function serviceAddress(Member $member, $memberAp): array
    {
        $device = Device::query()
            ->whereIn('user_id', $member->users()->pluck('id'))
            ->whereNotNull('address_point_id')
            ->with(['addressPoint.street', 'addressPoint.town'])
            ->orderBy('id')
            ->first();

        return self::address($device?->addressPoint ?? $memberAp);
    }

    /** @return array{street:string, town:string, zip:string, full:string} */
    private static function address($ap): array
    {
        $street   = $ap?->street?->street ?? '';
        $streetNo = $ap?->street_number ?? '';
        $town     = $ap?->town?->town ?? '';
        $zip      = $ap?->town?->zip_code ?? '';
        $streetFull = $street ? trim("{$street} {$streetNo}") : '';

        return [
            'street' => $streetFull,
            'town'   => $town,
            'zip'    => $zip,
            'full'   => $streetFull ? "{$streetFull}, {$zip} {$town}" : '',
        ];
    }
}
