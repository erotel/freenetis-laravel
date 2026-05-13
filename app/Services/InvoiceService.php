<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Member;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mpdf\Mpdf;

class InvoiceService
{
    // Faktury patří mezi citlivé soubory (osobní údaje, částky), proto storage/app/private,
    // konzistentně s contracts/, contract-attachments/, certs/. Pro migraci existujících
    // PDF z legacy Kohana umístění slouží příkaz `php artisan invoices:migrate-pdf-storage`.
    const VAT_RATE = 0.21;

    public static function pdfBasePath(): string
    {
        return storage_path('app/private/invoices');
    }

    /**
     * Generate invoice for services payment (payment_purpose=1).
     * Returns created Invoice or null on failure.
     */
    public function generateServicesInvoice(
        int $memberId,
        float $amount,
        int $bankAccountId,
        array $bankTransferIds = []
    ): ?Invoice {
        $member = Member::with([
            'accounts.variableSymbols',
            'addressPoint.street',
            'addressPoint.town',
        ])->find($memberId);

        if (!$member) return null;

        // Supplier = member ID=1 (the association)
        $org = Member::with(['addressPoint.street', 'addressPoint.town'])->find(1);

        $bankAccount = BankAccount::find($bankAccountId);

        // Sequential invoice_nr: YYYY00001 format
        $year  = now()->year;
        $minNr = $year * 100000;
        $maxNr = $year * 100000 + 99999;
        $maxExisting = Invoice::whereBetween('invoice_nr', [$minNr, $maxNr])->max('invoice_nr');
        $invoiceNr   = $maxExisting ? ((int)$maxExisting + 1) : ($minNr + 1);

        // Variable symbol from first account's first variable symbol, else member_id
        $varSym = $member->accounts
            ->flatMap(fn($a) => $a->variableSymbols)
            ->first()?->variable_symbol ?? $member->id;

        // Partner address
        $ap       = $member->addressPoint;
        $street   = $ap?->street?->street ?? '';
        $streetNr = $ap?->street_number ?? '';
        $town     = $ap?->town?->town ?? '';
        $zip      = $ap?->town?->zip_code ?? '';

        DB::beginTransaction();
        try {
            $invoice = Invoice::create([
                'member_id'                   => $memberId,
                'invoice_nr'                  => $invoiceNr,
                'invoice_type'                => Invoice::TYPE_ISSUED,
                'account_nr'                  => $bankAccount
                    ? $bankAccount->account_nr . '/' . $bankAccount->bank_nr
                    : '',
                'var_sym'                     => (float) $varSym,
                'con_sym'                     => null,
                'date_inv'                    => now()->toDateString(),
                'date_due'                    => now()->addDays(14)->toDateString(),
                'date_vat'                    => now()->toDateString(),
                'vat'                         => 1,
                'currency'                    => Setting::get('currency', 'CZK'),
                'note'                        => 'AUTO PVFREE SERVICES ' . now()->format('Y-m-d H:i'),
                'partner_name'                => $member->name,
                'partner_street'              => $street,
                'partner_street_number'       => $streetNr,
                'partner_town'                => $town,
                'partner_zip_code'            => $zip,
                'partner_country'             => null,
                'organization_identifier'     => $member->organization_identifier ?? null,
                'vat_organization_identifier' => $member->vat_organization_identifier ?? null,
            ]);

            // Main item: price without VAT derived from total amount
            $vatRate      = self::VAT_RATE;
            $vatAmount    = round($amount * ($vatRate / (1 + $vatRate)), 2);
            $priceNoVat   = round($amount - $vatAmount, 2);

            InvoiceItem::create([
                'invoice_id'             => $invoice->id,
                'name'                   => 'Platba za připojení k síti Internet',
                'code'                   => 'BANK-' . now()->format('Y-m'),
                'quantity'               => 1,
                'price'                  => $priceNoVat,
                'vat'                    => $vatRate,
                'author_fee'             => 0,
                'contractual_increase'   => 0,
                'service'                => 1,
            ]);

            // Rounding correction (haléře)
            $computed = round($priceNoVat + round($priceNoVat * $vatRate, 2), 2);
            $diff     = round($amount - $computed, 2);
            if (abs($diff) >= 0.01) {
                InvoiceItem::create([
                    'invoice_id'           => $invoice->id,
                    'name'                 => 'Zaokrouhlení',
                    'code'                 => 'ROUND',
                    'quantity'             => 1,
                    'price'                => $diff,
                    'vat'                  => 0,
                    'author_fee'           => 0,
                    'contractual_increase' => 0,
                    'service'              => 0,
                ]);
            }

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('InvoiceService: invoice creation failed: ' . $e->getMessage());
            return null;
        }

        // PDF generation (outside transaction — filesystem cannot be rolled back)
        $pdfPath = $this->generatePdf($invoice);
        if ($pdfPath) {
            $invoice->update(['pdf_filename' => $pdfPath]);
        }

        // Email queue
        $this->queueInvoiceEmail($invoice, $member, $pdfPath, $bankTransferIds, $amount);

        return $invoice;
    }

    /**
     * Queue payment confirmation email for members (payment_purpose=0).
     */
    public function queuePaymentConfirmation(
        int $memberId,
        float $amount,
        string $bankAccountName,
        array $bankTransferIds = []
    ): void {
        $emails = $this->getMemberEmails($memberId);
        if (empty($emails)) return;

        $from    = Setting::get('email_default_email', 'no-reply@freenetis.org');
        $subject = 'Oznámení o přijaté platbě';
        $body    = '<p>Vážený člene,</p>'
            . '<p>potvrzujeme přijetí Vaší platby ve výši <strong>'
            . number_format($amount, 2, ',', ' ') . ' Kč</strong>'
            . ' na účet <strong>' . htmlspecialchars($bankAccountName) . '</strong>.</p>'
            . '<p>Částka byla připsána na Váš virtuální účet.</p>'
            . '<p>S pozdravem,<br>PVfree.net, z.s.</p>';

        foreach ($emails as $email) {
            DB::table('email_queues')->insert([
                'from'        => $from,
                'to'          => $email,
                'subject'     => $subject,
                'body'        => $body,
                'state'       => 0,
                'access_time' => now(),
            ]);
        }
    }

    public function generatePdf(Invoice $invoice): ?string
    {
        try {
            $invoice->loadMissing(['items']);

            $org         = Member::with(['addressPoint.street', 'addressPoint.town'])->find(1);
            $bankAccount = BankAccount::find($invoice->bank_account_id);

            $year = (int) substr($invoice->date_inv, 0, 4) ?: now()->year;
            $dir  = self::pdfBasePath() . '/' . $year;

            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }

            $pdfPath = $dir . '/' . (int)$invoice->invoice_nr . '.pdf';

            $html = view('invoices.pdf', [
                'invoice'     => $invoice,
                'org'         => $org,
                'bankAccount' => $bankAccount,
                'items'       => $invoice->items,
            ])->render();

            $tmpDir = storage_path('framework/cache/mpdf');
            if (!is_dir($tmpDir)) {
                mkdir($tmpDir, 0775, true);
            }

            $mpdf = new Mpdf([
                'tempDir'           => $tmpDir,
                'mode'              => 'utf-8',
                'format'            => 'A4',
                'margin_top'        => 40,
                'margin_bottom'     => 20,
                'margin_left'       => 10,
                'margin_right'      => 10,
                'default_font'      => 'dejavusans',
                'default_font_size' => 9,
            ]);
            $mpdf->WriteHTML($html);
            $mpdf->Output($pdfPath, 'F');

            return $pdfPath;

        } catch (\Exception $e) {
            Log::error('InvoiceService: PDF generation failed: ' . $e->getMessage());
            return null;
        }
    }

    private function queueInvoiceEmail(
        Invoice $invoice,
        Member $member,
        ?string $pdfPath,
        array $bankTransferIds,
        float $amount
    ): void {
        $emails = $this->getMemberEmails($member->id);
        if (empty($emails)) return;

        $from    = Setting::get('email_default_email', 'no-reply@freenetis.org');
        $subject = 'Faktura ' . (int)$invoice->invoice_nr;
        $total   = number_format($invoice->price_vat_total, 2, ',', ' ');
        $ids     = implode(', ', $bankTransferIds);
        $body    = '<p>Vážený zákazníku,</p>'
            . '<p>v příloze zasíláme fakturu č. <strong>' . (int)$invoice->invoice_nr . '</strong>'
            . ' na částku <strong>' . $total . ' Kč</strong>.</p>'
            . ($ids ? '<p>ID platby: ' . htmlspecialchars($ids) . '</p>' : '')
            . '<p>Datum splatnosti: ' . $invoice->date_due . '</p>'
            . '<p>S pozdravem,<br>PVfree.net, z.s.</p>';

        foreach ($emails as $email) {
            $emailQueueId = DB::table('email_queues')->insertGetId([
                'from'        => $from,
                'to'          => $email,
                'subject'     => $subject,
                'body'        => $body,
                'state'       => 0,
                'access_time' => now(),
            ]);

            if ($pdfPath && file_exists($pdfPath)) {
                DB::table('email_queue_attachments')->insert([
                    'email_queue_id' => $emailQueueId,
                    'path'           => $pdfPath,
                    'name'           => 'faktura_' . (int)$invoice->invoice_nr . '.pdf',
                    'mime'           => 'application/pdf',
                    'created_at'     => now(),
                ]);
            }
        }
    }

    private function getMemberEmails(int $memberId): array
    {
        // contacts table has no user_id; join through users_contacts
        return DB::table('users_contacts')
            ->join('users', 'users.id', '=', 'users_contacts.user_id')
            ->join('contacts', 'contacts.id', '=', 'users_contacts.contact_id')
            ->where('users.member_id', $memberId)
            ->where('contacts.type', 20)  // 20 = E-mail (enum_types)
            ->whereNotNull('contacts.value')
            ->where('contacts.value', '!=', '')
            ->pluck('contacts.value')
            ->unique()
            ->values()
            ->toArray();
    }
}
