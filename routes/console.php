<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// CRON failure detection — must run every minute (mirrors Kohana Scheduler_Controller cron_last_active check)
Schedule::command('cron:heartbeat')->everyMinute();

// Auto-import FIO bank statements — every hour at :55, rules in bank_accounts_automatical_downloads
// determine which accounts are imported at which hour (mirrors Kohana AM_BANK_STATEMENTS='55')
Schedule::command('bank:import-statements')->cron('55 * * * *');

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

// Activate redirect for pending customers (type=18, unsigned contract); auto-clears when admin
// changes type 18 → 2 after the contract is signed. Toggleable via pending_customer_redirect_enabled.
Schedule::command('members:redirect-pending-customers')->hourly();

// SledovaniTV — denně synchronizovat seznam aktivních TV zákazníků (skips unless module enabled)
Schedule::command('sledovanitv:sync')->dailyAt('03:30');
