<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Documents\InvoicePdf;
use App\Domains\Billing\Repositories\InvoiceRepository;
use App\Models\BankAccount;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Member;
use App\Models\Message;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Faktura za služby k přijaté platbě (účet služeb, payment_purpose=1): číslo
 * roku, odběratel = člen, položka „Platba za připojení" bez DPH (21 % z částky
 * vč. DPH) + případné zaokrouhlení na haléře; PDF a e-mail s fakturou.
 */
class IssueServicesInvoiceAction
{
    public const VAT_RATE = 0.21;

    public function __construct(
        private InvoiceRepository $invoices,
        private InvoicePdf $pdf,
    ) {
    }

    /** @return ?Invoice null = člen neexistuje / zápis selhal */
    public function handle(int $memberId, float $amount, int $bankAccountId, array $bankTransferIds = []): ?Invoice
    {
        $member = Member::with(['accounts.variableSymbols', 'addressPoint.street', 'addressPoint.town'])->find($memberId);
        if (!$member) return null;

        $bankAccount = BankAccount::find($bankAccountId);
        $varSym = $member->accounts->flatMap(fn ($a) => $a->variableSymbols)->first()?->variable_symbol ?? $member->id;
        $ap     = $member->addressPoint;

        DB::beginTransaction();
        try {
            $invoice = Invoice::create([
                'member_id'                   => $memberId,
                'invoice_nr'                  => $this->invoices->nextNumber(now()->year),
                'invoice_type'                => Invoice::TYPE_ISSUED,
                'account_nr'                  => $bankAccount ? $bankAccount->account_nr . '/' . $bankAccount->bank_nr : '',
                'var_sym'                     => (float) $varSym,
                'con_sym'                     => null,
                'date_inv'                    => now()->toDateString(),
                'date_due'                    => now()->addDays(14)->toDateString(),
                'date_vat'                    => now()->toDateString(),
                'vat'                         => 1,
                'currency'                    => Setting::get('currency', 'CZK'),
                'note'                        => 'AUTO PVFREE SERVICES ' . now()->format('Y-m-d H:i'),
                'partner_name'                => $member->name,
                'partner_street'              => $ap?->street?->street ?? '',
                'partner_street_number'       => $ap?->street_number ?? '',
                'partner_town'                => $ap?->town?->town ?? '',
                'partner_zip_code'            => $ap?->town?->zip_code ?? '',
                'partner_country'             => null,
                'organization_identifier'     => $member->organization_identifier ?? null,
                'vat_organization_identifier' => $member->vat_organization_identifier ?? null,
            ]);
            $this->addItems($invoice, $amount);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('IssueServicesInvoiceAction: invoice creation failed: ' . $e->getMessage());
            return null;
        }

        // PDF mimo transakci — soubor nejde vrátit
        $path = $this->pdf->render($invoice);
        if ($path) {
            $invoice->update(['pdf_filename' => $path]);
        }
        $this->email($invoice, $member, $path, $bankTransferIds);

        return $invoice;
    }

    /** Položka bez DPH z částky vč. DPH + zaokrouhlení, aby součet seděl na haléř. */
    private function addItems(Invoice $invoice, float $amount): void
    {
        $vatAmount  = round($amount * (self::VAT_RATE / (1 + self::VAT_RATE)), 2);
        $priceNoVat = round($amount - $vatAmount, 2);

        InvoiceItem::create([
            'invoice_id' => $invoice->id, 'name' => 'Platba za připojení k síti Internet', 'code' => 'BANK-' . now()->format('Y-m'),
            'quantity' => 1, 'price' => $priceNoVat, 'vat' => self::VAT_RATE, 'author_fee' => 0, 'contractual_increase' => 0, 'service' => 1,
        ]);

        $diff = round($amount - round($priceNoVat + round($priceNoVat * self::VAT_RATE, 2), 2), 2);
        if (abs($diff) >= 0.01) {
            InvoiceItem::create([
                'invoice_id' => $invoice->id, 'name' => 'Zaokrouhlení', 'code' => 'ROUND',
                'quantity' => 1, 'price' => $diff, 'vat' => 0, 'author_fee' => 0, 'contractual_increase' => 0, 'service' => 0,
            ]);
        }
    }

    /**
     * E-mail s fakturou všem e-mailům člena; šablona „Payment confirmation -
     * customer" (migrace 2026_05_13_140000), chybí-li → záložní text.
     */
    private function email(Invoice $invoice, Member $member, ?string $pdfPath, array $bankTransferIds): void
    {
        $emails = $this->invoices->memberEmails($member->id);
        if (empty($emails)) return;

        $no    = (string) (int) $invoice->invoice_nr;
        $extra = [
            'invoice_nr'     => $no,
            'payment_amount' => number_format((float) $invoice->price_vat_total, 2, ',', ' '),
            'payment_ids'    => implode(', ', $bankTransferIds),
            'date_due'       => (string) $invoice->date_due,
        ];

        $message = Message::where('name', 'Payment confirmation - customer')->first();
        $body = ($message && !empty($message->email_text))
            ? Message::substitute($message->email_text, Message::buildPlaceholders($member->id, $extra))
            : '<p>Vážený zákazníku,</p>'
                . '<p>v příloze zasíláme fakturu č. <strong>' . $no . '</strong>'
                . ' na částku <strong>' . $extra['payment_amount'] . ' Kč</strong>.</p>'
                . ($extra['payment_ids'] ? '<p>ID platby: ' . htmlspecialchars($extra['payment_ids']) . '</p>' : '')
                . '<p>Datum splatnosti: ' . $extra['date_due'] . '</p>'
                . '<p>S pozdravem,<br>PVfree.net, z.s.</p>';

        foreach ($emails as $email) {
            $id = DB::table('email_queues')->insertGetId([
                'from' => Setting::get('email_default_email', 'no-reply@freenetis.org'), 'to' => $email,
                'subject' => 'Faktura ' . $no, 'body' => $body, 'state' => 0, 'access_time' => now(),
            ]);
            if ($pdfPath && file_exists($pdfPath)) {
                DB::table('email_queue_attachments')->insert([
                    'email_queue_id' => $id, 'path' => $pdfPath, 'name' => 'faktura_' . $no . '.pdf',
                    'mime' => 'application/pdf', 'created_at' => now(),
                ]);
            }
        }
    }
}
