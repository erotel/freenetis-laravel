<?php

namespace App\Domains\Billing;

use App\Domains\Billing\Events\FeesDeducted;
use App\Domains\Billing\Events\PaymentReceived;
use App\Domains\Billing\Listeners\BackchargeAfterPayment;
use App\Domains\Billing\Listeners\IssuePaymentDocument;
use App\Domains\Billing\Listeners\WriteDeductionAudit;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Registrace eventů domény Billing. Listenery běží synchronně v pořadí registrace. */
class BillingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(FeesDeducted::class, WriteDeductionAudit::class);

        // Doklad před dohnáním poplatků (pořadí jako dřívější handlePostImport).
        Event::listen(PaymentReceived::class, IssuePaymentDocument::class);
        Event::listen(PaymentReceived::class, BackchargeAfterPayment::class);
    }
}
