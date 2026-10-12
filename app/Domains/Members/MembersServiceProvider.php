<?php

namespace App\Domains\Members;

use App\Domains\Members\Events\MemberApproved;
use App\Domains\Members\Listeners\SendApprovalEmail;
use App\Domains\Members\Listeners\WriteApprovalAudit;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Registrace eventů domény Members. Listenery běží synchronně v pořadí registrace. */
class MembersServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(MemberApproved::class, WriteApprovalAudit::class);
        Event::listen(MemberApproved::class, SendApprovalEmail::class);
    }
}
