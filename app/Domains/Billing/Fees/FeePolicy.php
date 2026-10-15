<?php

namespace App\Domains\Billing\Fees;

use App\Domains\Billing\Repositories\FeeRepository;
use App\Models\Setting;
use App\Services\AllowedSubnetFeesResolver;

/**
 * Jediná pravda o tom, KOLIK má člen měsíčně platit.
 *
 * Pravidelný (členský) poplatek — třístupňový fallback:
 *   1. individuální  — members_fees 'regular member fee', nejnižší priorita číslem
 *   2. cena tarifu   — speed_classes.price podle members.speed_class_id
 *   3. základní      — Setting default_fee_member_type_<typ> (drží fee_id) → fees.fee
 * float = částka (i 0.0 = osvobozeno, NEpropadá dál); null = nic na žádné úrovni.
 * NULL cena tarifu propadá na základní, explicitní 0 znamená „neúčtovat".
 *
 * Měsíční příplatky (transfer type 6) = dodatečné služby (members_fees
 * 'additional service', součet) + placená přípojná místa (AllowedSubnetFeesResolver).
 *
 * POZOR: hromadná srážka (LedgerRepository::dueMemberFees / dueAdditionalServiceFees)
 * a upomínky (NotificationActivation) drží ekvivalentní bulk SQL kvůli výkonu —
 * změna pravidla musí jít i tam. Známé odchylky zachycuje
 * tests/Characterization/Billing/FeeRulesMatrixTest.
 */
class FeePolicy
{
    public const REGULAR_FEE        = 'regular member fee';
    public const ADDITIONAL_SERVICE = 'additional service';

    /**
     * Typy, kterým se měsíční poplatek a příplatky NEstrhávají: žadatel (1),
     * čekající člen/zákazník (17/18) — služba ještě neběží; bývalí (15/16) —
     * už odešli. regularFee() jim přesto vrací budoucí/poslední cenu pro
     * zobrazení (smlouva, QR).
     */
    public const NOT_BILLED_TYPES = [1, 15, 16, 17, 18];

    public static function isBilled(int $memberType): bool
    {
        return !in_array($memberType, self::NOT_BILLED_TYPES, true);
    }

    public function __construct(private FeeRepository $fees)
    {
    }

    public function regularFee(int $memberId, int $memberType, string $date): ?float
    {
        return $this->individualFee($memberId, $date)
            ?? $this->tarifPrice($memberId)
            ?? $this->defaultFeeByType($memberType);
    }

    /** Úroveň 1: individuální 'regular member fee' (nejnižší priorita číslem). */
    public function individualFee(int $memberId, string $date): ?float
    {
        return $this->fees->activeMemberFee($memberId, self::REGULAR_FEE, $date);
    }

    /** Úroveň 2: ceníková cena tarifu člena. */
    public function tarifPrice(int $memberId): ?float
    {
        return $this->fees->tarifPrice($memberId);
    }

    /**
     * Úroveň 3: základní poplatek dle typu (setting drží fee_id, ne částku).
     * Čekající typy nemají vlastní default — mapují se na cílový typ po aktivaci
     * (billingType), aby smlouva/QR žadatele ukazovaly budoucí cenu.
     */
    public function defaultFeeByType(int $memberType): ?float
    {
        $feeId = (int) Setting::get('default_fee_member_type_' . self::billingType($memberType), 0);
        if ($feeId <= 0) {
            return null;
        }
        return $this->fees->feeAmount($feeId);
    }

    /** Žadatel/čekající zákazník (1, 18) → zákazník (2), čekající člen (17) → člen (90). */
    public static function billingType(int $memberType): int
    {
        return match ($memberType) {
            1, 18   => 2,
            17      => 90,
            default => $memberType,
        };
    }

    // ── příplatky (type 6) ───────────────────────────────────────────────────

    /** Součet měsíčních příplatků: dodatečné služby + placená přípojná místa. */
    public function surcharges(int $memberId, string $date): float
    {
        return $this->additionalServicesTotal($memberId, $date) + AllowedSubnetFeesResolver::total($memberId);
    }

    public function additionalServicesTotal(int $memberId, string $date): float
    {
        return $this->fees->activeMemberFeeSum($memberId, self::ADDITIONAL_SERVICE, $date);
    }

    /** @return array<int, array{fee_id: int, name: string, fee: float}> rozpis služeb dle priority */
    public function additionalServiceItems(int $memberId, string $date): array
    {
        return $this->fees->activeMemberFeeItems($memberId, self::ADDITIONAL_SERVICE, $date);
    }
}
