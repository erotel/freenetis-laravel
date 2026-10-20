<?php

namespace App\Console\Commands;

use App\Domains\Billing\Actions\ExportPohodaAction;
use App\Domains\Notifications\Repositories\EmailQueueRepository;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExportPohodaMonthly extends Command
{
    protected $signature   = 'pohoda:export-monthly
                                {--month= : YYYY-MM, defaults to last month}
                                {--force : Run even if already sent this period}';
    protected $description = 'Export monthly invoices and refunds to Pohoda XML and email to accountant';

    public function handle(ExportPohodaAction $service, EmailQueueRepository $emails): int
    {
        // Determine target month
        if ($this->option('month')) {
            [$year, $month] = array_map('intval', explode('-', $this->option('month'), 2));
        } else {
            $lastMonth = now()->subMonth();
            $year  = (int)$lastMonth->format('Y');
            $month = (int)$lastMonth->format('m');
        }

        $monthKey = sprintf('%04d-%02d', $year, $month);

        // Idempotent guard
        if (!$this->option('force')) {
            $lastSent = (string)Setting::get('pohoda_export_last_sent', '');
            if ($lastSent === $monthKey) {
                $this->info("Already exported for {$monthKey}, skipping. Use --force to override.");
                return 0;
            }
        }

        $this->info("Exporting Pohoda for {$monthKey}...");

        $attachments = [];

        // Step 1: invoices
        try {
            $invoiceFile = $service->invoices($year, $month);
            if ($invoiceFile) {
                $attachments[] = $invoiceFile;
                $this->info('Invoices XML: ' . basename($invoiceFile));
            } else {
                $this->info('No invoices for this month.');
            }
        } catch (\Throwable $e) {
            $this->error('Invoice export failed: ' . $e->getMessage());
            Log::error('pohoda:export-monthly invoice error', ['error' => $e->getMessage()]);
        }

        // Step 2: refunds
        try {
            $refundFile = $service->refunds($year, $month);
            if ($refundFile) {
                $attachments[] = $refundFile;
                $this->info('Refunds XML: ' . basename($refundFile));
            } else {
                $this->info('No refunds pending.');
            }
        } catch (\Throwable $e) {
            $this->error('Refund export failed: ' . $e->getMessage());
            Log::error('pohoda:export-monthly refund error', ['error' => $e->getMessage()]);
        }

        if (empty($attachments)) {
            $this->info('Nothing to export, skipping email.');
            return 0;
        }

        // Step 3: queue email
        $accountantEmail = (string)Setting::get('pohoda_accountant_email', '');
        $fromEmail       = (string)Setting::get('email_default_email', 'noreply@pvfree.net');

        if (!$accountantEmail) {
            $this->warn('pohoda_accountant_email not set in config — skipping email.');
        } else {
            $subject = sprintf('Export POHODA %d/%d', $month, $year);

            $lines = ["Dobrý den,", "", "v příloze zasíláme export faktur"];
            if (count($attachments) > 1) {
                $lines[2] .= ' a vratek';
            }
            $lines[] = sprintf("pro období %02d/%04d.", $month, $year);
            $lines[] = "";
            $lines[] = "Soubory:";
            foreach ($attachments as $file) {
                $lines[] = '- ' . basename($file);
            }
            $lines[] = "";
            $lines[] = "FreenetIS";

            $body = nl2br(htmlspecialchars(implode("\n", $lines), ENT_QUOTES, 'UTF-8'));

            $emailId = $emails->enqueue($fromEmail, $accountantEmail, $subject, $body);
            foreach ($attachments as $file) {
                $emails->attach($emailId, $file, basename($file), 'application/xml');
            }

            $this->info("Email queued to {$accountantEmail}: {$subject}");
        }

        // Mark as sent
        Setting::set('pohoda_export_last_sent', $monthKey);
        $this->info("Done. pohoda_export_last_sent set to {$monthKey}.");

        return 0;
    }
}
