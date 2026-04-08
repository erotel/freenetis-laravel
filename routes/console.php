<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Send queued emails every  minute
Schedule::command('email:send-queue')->everyMinute();

// Export invoices + refunds to Pohoda XML on the 1st of each month at 06:00
Schedule::command('pohoda:export-monthly')->monthlyOn(1, '06:00');

// Deduct member/entrance/device fees daily at 00:03 (skips unless today matches deduct_day)
Schedule::command('fees:deduct')->dailyAt('00:03');

// Activate debtor/payment-notice notifications every minute (rules filter by day/hour internally)
Schedule::command('notifications:activate')->everyMinute();

// Update allowed subnets redirections every hour (skips unless enabled in settings)
Schedule::command('subnets:update-allowed')->everyMinute();
