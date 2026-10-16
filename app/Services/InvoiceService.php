<?php

namespace App\Services;

use App\Domains\Billing\Actions\IssueServicesInvoiceAction;
use App\Domains\Billing\Actions\QueuePaymentConfirmationAction;
use App\Domains\Billing\Documents\InvoicePdf;
use App\Models\Invoice;

/**
 * Fasáda → doména Billing (IssueServicesInvoiceAction, QueuePaymentConfirmationAction,
 * Documents/InvoicePdf). Zůstává kvůli stávajícím volajícím.
 */
class InvoiceService
{
    const VAT_RATE = IssueServicesInvoiceAction::VAT_RATE;

    public static function pdfBasePath(): string
    {
        return InvoicePdf::basePath();
    }

    public function generateServicesInvoice(int $memberId, float $amount, int $bankAccountId, array $bankTransferIds = []): ?Invoice
    {
        return app(IssueServicesInvoiceAction::class)->handle($memberId, $amount, $bankAccountId, $bankTransferIds);
    }

    public function queuePaymentConfirmation(int $memberId, float $amount, string $bankAccountName, array $bankTransferIds = []): void
    {
        app(QueuePaymentConfirmationAction::class)->handle($memberId, $amount, $bankAccountName, $bankTransferIds);
    }

    public function generatePdf(Invoice $invoice): ?string
    {
        return app(InvoicePdf::class)->render($invoice);
    }
}
