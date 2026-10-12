<?php

namespace App\Domains\Members\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Člen byl schválen/aktivován: čekatel (17/18) tlačítkem nebo editací, nebo
 * žadatel/čekající zákazník (1/18 → 2) online podpisem smlouvy.
 */
class MemberApproved
{
    use Dispatchable;

    public function __construct(
        public readonly int $memberId,
        public readonly int $previousType,
        public readonly int $newType,
    ) {
    }
}
