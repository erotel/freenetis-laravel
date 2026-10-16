<?php

namespace App\Domains\Contracts\Support;

use App\Domains\Billing\Fees\FeePolicy;
use App\Models\Member;
use App\Models\SpeedClass;
use Illuminate\Support\Facades\DB;

/**
 * Snímek tarifu do smlouvy / dodatku: název a cena rychlosti (bez ceny →
 * základní poplatek dle typu) + individuální zvýhodněná cena člena a do kdy.
 */
class TariffSnapshot
{
    public function __construct(private FeePolicy $fees)
    {
    }

    /** Současný (živý) tarif člena. */
    public function current(Member $member): array
    {
        return $this->build($member, $member->speedClass?->name ?? '', $member->speedClass?->price);
    }

    /** Navrhovaná třída rychlosti; sleva je vlastnost člena, snímá se stejně. */
    public function forSpeedClass(Member $member, int $speedClassId): array
    {
        $sc = SpeedClass::find($speedClassId);
        return $this->build($member, $sc?->name ?? '', $sc?->price);
    }

    private function build(Member $member, string $speedName, $tarifPrice): array
    {
        $basePrice = $tarifPrice !== null
            ? (float) $tarifPrice
            : ($this->fees->defaultFeeByType((int) $member->type) ?? 0.0);

        $today      = now()->toDateString();
        $individual = DB::table('members_fees as mf')
            ->join('fees as f', 'f.id', '=', 'mf.fee_id')
            ->join('enum_types as et', 'et.id', '=', 'f.type_id')
            ->whereRaw("LOWER(et.value) = 'regular member fee'")
            ->where('mf.member_id', $member->id)
            ->where('mf.activation_date', '<=', $today)
            ->where('mf.deactivation_date', '>=', $today)
            ->orderBy('mf.priority')
            ->first(['f.fee', 'mf.deactivation_date']);

        return [
            'speed_name'           => $speedName,
            'price'                => $basePrice,
            'price_after_discount' => $individual !== null ? (float) $individual->fee : null,
            'discount_until'       => $individual !== null ? $individual->deactivation_date : null,
        ];
    }
}
