<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Send queued emails every minute
Schedule::command('email:send-queue')->everyMinute();

// Send queued SMS messages every minute via configured drivers
Schedule::command('sms:send')->everyMinute();

// Export invoices + refunds to Pohoda XML on the 1st of each month at 06:00
Schedule::command('pohoda:export-monthly')->monthlyOn(1, '06:00');

// Deduct member/entrance/device fees daily at 00:03 (skips unless today matches deduct_day)
Schedule::command('fees:deduct')->dailyAt('00:03');

// Activate debtor/payment-notice notifications every minute (rules filter by day/hour internally)
Schedule::command('notifications:activate')->everyMinute();

// Update allowed subnets redirections every hour (skips unless enabled in settings)
Schedule::command('subnets:update-allowed')->everyMinute();

// Mark expired members as former, remove devices, activate redirect at 00:09
Schedule::command('members:redirect-former')->dailyAt('00:09');

// Activate redirect for interrupted members at 00:09 (skips unless membership_interrupt_enabled)
Schedule::command('members:redirect-interrupted')->dailyAt('00:09');

// Activate redirect for applicants with expired connection test (skips unless duration configured)
Schedule::command('members:redirect-expired-applicants')->hourly();
