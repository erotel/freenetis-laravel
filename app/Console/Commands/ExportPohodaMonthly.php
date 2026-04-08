<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\PohodaExportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExportPohodaMonthly extends Command
{
    protected $signature   = 'pohoda:export-monthly
                                {--month= : YYYY-MM, defaults to last month}
                                {--force : Run even if already sent this period}';
    protected $description = 'Export monthly invoices and refunds to Pohoda XML and email to accountant';

    public function handle(PohodaExportService $service): int
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
            $invoiceFile = $service->exportInvoices($year, $month);
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
            $refundFile = $service->exportRefunds($year, $month);
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

            $emailId = DB::table('email_queues')->insertGetId([
                'from'    => $fromEmail,
                'to'      => $accountantEmail,
                'subject' => $subject,
                'body'    => $body,
                'state'   => 0,
            ]);

            foreach ($attachments as $file) {
                DB::table('email_queue_attachments')->insert([
                    'email_queue_id' => $emailId,
                    'path'           => $file,
                    'name'           => basename($file),
                    'mime'           => 'application/xml',
                    'created_at'     => now(),
                ]);
            }

            $this->info("Email queued to {$accountantEmail}: {$subject}");
        }

        // Mark as sent
        Setting::set('pohoda_export_last_sent', $monthKey);
        $this->info("Done. pohoda_export_last_sent set to {$monthKey}.");

        return 0;
    }
}
