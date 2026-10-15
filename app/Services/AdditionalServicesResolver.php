<?php

namespace App\Services;

use App\Domains\Billing\Fees\FeePolicy;

/**
 * Fasáda → App\Domains\Billing\Fees\FeePolicy (dodatečné služby, enum
 * 'additional service' — měsíční poplatky navíc k tarifu, transfer type 6).
 * Zůstává kvůli stávajícím volajícím; nový kód používá FeePolicy přímo.
 */
class AdditionalServicesResolver
{
    /** Součet všech aktivních dodatečných služeb člena k danému datu. */
    public static function total(int $memberId, string $date): float
    {
        return app(FeePolicy::class)->additionalServicesTotal($memberId, $date);
    }

    /** Rozpis [['fee_id' => int, 'name' => string, 'fee' => float], ...] dle priority. */
    public static function items(int $memberId, string $date): array
    {
        return app(FeePolicy::class)->additionalServiceItems($memberId, $date);
    }
}
