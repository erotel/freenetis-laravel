<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Repositories\InvoiceRepository;
use App\Models\Member;
use App\Models\Message;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Potvrzení přijaté platby (účet příspěvků, payment_purpose=0) e-mailem všem
 * e-mailům člena: systémová zpráva 9 („Received payment notice" — daňový
 * doklad s rozpadem DPH), chybí-li text → záložní potvrzení.
 */
class QueuePaymentConfirmationAction
{
    public function __construct(private InvoiceRepository $invoices)
    {
    }

    public function handle(int $memberId, float $amount, string $bankAccountName, array $bankTransferIds = []): void
    {
        $emails = $this->invoices->memberEmails($memberId);
        if (empty($emails)) return;

        // Použije systémovou Message id=9 ("Received payment notice", DAŇOVÝ DOKLAD).
        // Tu už má každá Kohana instance v seedu, takže neimplementuju lookup by name —
        // používám konstantu. Pokud by chyběla, fallback na konzervativní hardcoded text.
        $message = Message::find(9);

        $from    = Setting::get('email_default_email', 'no-reply@freenetis.org');
        $prefix  = Setting::get('email_subject_prefix', '');
        $subject = $message
            ? ($prefix ? $prefix . ' :: ' : '') . $message->name
            : 'Oznámení o přijaté platbě';

        if (!$message || empty($message->email_text)) {
            $body = '<p>Vážený člene,</p>'
                . '<p>potvrzujeme přijetí Vaší platby ve výši <strong>'
                . number_format($amount, 2, ',', ' ') . ' Kč</strong>'
                . ' na účet <strong>' . htmlspecialchars($bankAccountName) . '</strong>.</p>'
                . '<p>Částka byla připsána na Váš virtuální účet.</p>'
                . '<p>S pozdravem,<br>PVfree.net, z.s.</p>';
        } else {
            $member = Member::with([
                'accounts.variableSymbols',
                'addressPoint.street',
                'addressPoint.town',
            ])->find($memberId);

            $vatRate     = IssueServicesInvoiceAction::VAT_RATE;
            $amountNoVat = round($amount / (1 + $vatRate), 2);
            $vatAmount   = round($amount - $amountNoVat, 2);

            $varSym = (string) ($member?->accounts
                ->flatMap(fn($a) => $a->variableSymbols)
                ->first()
                ?->variable_symbol ?? $memberId);

            $ap        = $member?->addressPoint;
            $paymentId = !empty($bankTransferIds) ? (string) $bankTransferIds[0] : '';

            $extra = [
                'payment_id'                  => $paymentId,
                'payment_amount'              => number_format($amount,      2, ',', ' '),
                'payment_amount_novat'        => number_format($amountNoVat, 2, ',', ' '),
                'payment_vat'                 => number_format($vatAmount,   2, ',', ' '),
                'payment_date'                => now()->format('d.m.Y'),
                'variable_symbol'             => $varSym,
                'address_street'              => (string) ($ap?->street?->street ?? ''),
                'address_street_number'       => (string) ($ap?->street_number ?? ''),
                'address_town'                => (string) ($ap?->town?->town ?? ''),
                'organization_identifier'     => (string) ($member?->organization_identifier ?? ''),
                'vat_organization_identifier' => (string) ($member?->vat_organization_identifier ?? ''),
            ];

            $body = Message::substitute(
                $message->email_text,
                app(\App\Domains\Notifications\Support\MemberPlaceholders::class)->forMember($memberId, $extra)
            );
        }

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
}
