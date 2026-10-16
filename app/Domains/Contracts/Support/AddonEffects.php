<?php

namespace App\Domains\Contracts\Support;

use App\Models\AllowedSubnet;
use App\Models\Contract;
use App\Models\ContractAddon;
use App\Models\Member;
use App\Models\MemberFee;

/**
 * Dopady dodatku na člena — teprve po podpisu (přidání) / vydání (zrušení),
 * apply-on-sign. Billing čte živý stav člena, takže nová cena naskočí sama.
 * Změny přes Eloquent instance (audit), každá s událostí v historii smlouvy.
 */
class AddonEffects
{
    /** Tarif: rychlost člena = navrhovaná (jen při změně). */
    public function applyTariff(ContractAddon $addon): void
    {
        if (!$addon->new_speed_class_id) {
            return;
        }
        $member = $this->member($addon);
        if (!$member) {
            return;
        }
        $old = $member->speed_class_id;
        if ((int) $old === (int) $addon->new_speed_class_id) {
            return;
        }
        $member->update(['speed_class_id' => $addon->new_speed_class_id]);

        ContractEventLog::record($addon->contract_id, 'tariff_addon_applied', [
            'addon_id'           => $addon->id,
            'member_id'          => $member->id,
            'old_speed_class_id' => $old,
            'new_speed_class_id' => (int) $addon->new_speed_class_id,
        ], JSON_UNESCAPED_UNICODE);
    }

    /** Služba: přiřadit poplatek od účinnosti dodatku (idempotentně — aktivní stejná služba = nic). */
    public function assignServiceFee(ContractAddon $addon): void
    {
        $memberId = $this->memberId($addon);
        if (!$memberId) {
            return;
        }
        $from  = $this->effective($addon);
        $today = now()->toDateString();

        $already = MemberFee::where('member_id', $memberId)
            ->where('fee_id', $addon->fee_id)
            ->whereDate('activation_date', '<=', $today)
            ->whereDate('deactivation_date', '>=', $today)
            ->exists();
        if ($already) {
            return;
        }

        MemberFee::create([
            'member_id'         => $memberId,
            'fee_id'            => $addon->fee_id,
            'activation_date'   => $from,
            'deactivation_date' => '9999-12-31',
            'priority'          => 1,
            'comment'           => 'Dodatek č. ' . ($addon->addon_no ?? '?') . ' ke smlouvě (dodatečná služba)',
        ]);

        ContractEventLog::record($addon->contract_id, 'service_addon_applied', [
            'addon_id'  => $addon->id,
            'member_id' => $memberId,
            'fee_id'    => (int) $addon->fee_id,
            'from'      => $from,
        ], JSON_UNESCAPED_UNICODE);
    }

    /** Služba: ukončit dosud platná přiřazení poplatku k účinnosti dodatku. */
    public function removeServiceFee(ContractAddon $addon): void
    {
        $memberId = $this->memberId($addon);
        if (!$memberId) {
            return;
        }
        $to   = $this->effective($addon);
        $rows = MemberFee::where('member_id', $memberId)
            ->where('fee_id', $addon->fee_id)
            ->whereDate('deactivation_date', '>=', now()->toDateString())
            ->get();

        foreach ($rows as $row) {
            $row->update(['deactivation_date' => $to]);
        }
        if ($rows->isEmpty()) {
            return;
        }

        ContractEventLog::record($addon->contract_id, 'service_addon_removal_applied', [
            'addon_id'  => $addon->id,
            'member_id' => $memberId,
            'fee_id'    => (int) $addon->fee_id,
            'until'     => $to,
            'count'     => $rows->count(),
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Přípojné místo: účtování (charged). Místo musí patřit členovi smlouvy; zapnout
     * jen s vlastní rychlostí (jako AllowedSubnetController::updateBilling).
     */
    public function setPlaceCharged(ContractAddon $addon, bool $charged): void
    {
        $memberId = $this->memberId($addon);
        if (!$memberId) {
            return;
        }
        $place = AllowedSubnet::where('id', $addon->allowed_subnet_id)->where('member_id', $memberId)->first();
        if (!$place || ($charged && !$place->speed_class_id) || (bool) $place->charged === $charged) {
            return;
        }

        $place->update(['charged' => $charged]);

        ContractEventLog::record($addon->contract_id, $charged ? 'connection_point_addon_applied' : 'connection_point_addon_removal_applied', [
            'addon_id'          => $addon->id,
            'member_id'         => $memberId,
            'allowed_subnet_id' => (int) $addon->allowed_subnet_id,
            'charged'           => $charged,
        ], JSON_UNESCAPED_UNICODE);
    }

    private function memberId(ContractAddon $addon): ?int
    {
        $id = Contract::find($addon->contract_id)?->member_id;
        return $id ? (int) $id : null;
    }

    private function member(ContractAddon $addon): ?Member
    {
        $id = $this->memberId($addon);
        return $id ? Member::find($id) : null;
    }

    private function effective(ContractAddon $addon): string
    {
        return $addon->effective_date ? $addon->effective_date->toDateString() : now()->toDateString();
    }
}
