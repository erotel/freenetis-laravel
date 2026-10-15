<?php

namespace App\Domains\Members\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Člen byl trvale smazán i s daty (dispatch po commitu). $snapshot = řádek members před smazáním. */
class MemberPurged
{
    use Dispatchable;

    public function __construct(
        public readonly int $memberId,
        public readonly array $snapshot,
    ) {
    }
}
