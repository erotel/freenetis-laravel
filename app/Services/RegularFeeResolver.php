<?php

namespace App\Services;

use App\Domains\Billing\Fees\FeePolicy;

/**
 * Fasáda → App\Domains\Billing\Fees\FeePolicy (pravidla výše poplatku).
 * Zůstává kvůli stávajícím volajícím; nový kód používá FeePolicy přímo.
 */
class RegularFeeResolver
{
    public static function amount(int $memberId, int $memberType, string $date): ?float
    {
        return app(FeePolicy::class)->regularFee($memberId, $memberType, $date);
    }

    public static function individualFee(int $memberId, string $date): ?float
    {
        return app(FeePolicy::class)->individualFee($memberId, $date);
    }

    public static function tarifPrice(int $memberId): ?float
    {
        return app(FeePolicy::class)->tarifPrice($memberId);
    }

    public static function defaultFeeByType(int $memberType): ?float
    {
        return app(FeePolicy::class)->defaultFeeByType($memberType);
    }
}
