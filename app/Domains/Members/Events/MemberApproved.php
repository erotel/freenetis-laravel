<?php

namespace App\Domains\Members\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Čekatel (17/18) byl schválen na aktivního člena/zákazníka. */
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
