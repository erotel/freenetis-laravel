<?php

use App\Domains\Access\AccessServiceProvider;
use App\Domains\Billing\BillingServiceProvider;
use App\Domains\Members\MembersServiceProvider;
use App\Domains\Network\NetworkServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    MembersServiceProvider::class,
    AccessServiceProvider::class,
    BillingServiceProvider::class,
    NetworkServiceProvider::class,
];
