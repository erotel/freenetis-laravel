<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Send queued emails every 5 minutes
Schedule::command('email:send-queue')->everyFiveMinutes();

// Export invoices + refunds to Pohoda XML on the 1st of each month at 06:00
Schedule::command('pohoda:export-monthly')->monthlyOn(1, '06:00');
