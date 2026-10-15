<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Repositories\LedgerRepository;
use App\Domains\Billing\Repositories\RefundRepository;
use App\Domains\Billing\Support\AccountAttribute;
use App\Domains\Billing\Support\TerminationRefund;
use App\Domains\Billing\Support\TransferType;
use App\Helpers\MemberType;
use App\Services\AuditLogger;
use App\Services\RefundPdfService;

/**
 * Vratka při ukončení členství: návrh odchozí platby, číslo + PDF dobropisu
 * (zákazník i člen; do invoices se nezapisuje — samostatný doklad jako Kohana
 * Refund_Pdf), fronta pro Pohodu a vymazání kreditu převodem na provozní účet.
 *
 * Z kreditu odchází při odchodu dnes CELÝ zůstatek; ve výpovědní lhůtě JEN
 * vracená částka — zbytek pokryje poplatky do odchodu (incident 6235).
 *
 * Volá se uvnitř transakce (číslování dobropisů drží zámek do commitu).
 */
class IssueTerminationRefundAction
{
    private const REASON = 'termination_refund';

    public function __construct(
        private RefundRepository $refunds,
        private LedgerRepository $ledger,
        private RefundPdfService $pdf,
    ) {
    }

    /** @return ?TerminationRefund null = pro typ člena není nastaven bankovní účet */
    public function handle(int $memberId, int $memberType, string $leavingDate, string $targetAccount,
                           float $amount, bool $clearOnlyRefund, int $createdBy): ?TerminationRefund
    {
        $bankAccountId = $this->refunds->bankAccountIdFor($memberType);
        if (!$bankAccountId) {
            return null;
        }

        $amount = round($amount, 2);
        $outgoingPaymentId = $this->refunds->createOutgoingPayment(
            $bankAccountId, $targetAccount, $amount, 'Vratka při ukončení členství', self::REASON, $createdBy
        );

        $isCustomer = in_array($memberType, [MemberType::CUSTOMER, MemberType::PENDING_CUSTOMER], true);
        $docNumber  = $this->refunds->nextDocNumber($isCustomer);
        $pdfPath    = $this->pdf->generate($memberId, $docNumber, $memberType, $leavingDate, $targetAccount, $amount);

        // member_type = PŮVODNÍ typ (2/90…), ne bývalý
        $this->refunds->queueForPohoda($memberId, $memberType, $docNumber, $outgoingPaymentId, $targetAccount, $amount,
            self::REASON, 'Vratka při ukončení ' . ($isCustomer ? 'smlouvy' : 'členství'));

        AuditLogger::log('created', 'outgoing_payments', $outgoingPaymentId, null, [
            'member_id'      => $memberId,
            'amount'         => $amount,
            'target_account' => $targetAccount,
            'doc_number'     => $docNumber,
            'reason'         => self::REASON,
        ]);

        $this->clearCredit($memberId, $clearOnlyRefund ? $amount : null);

        return new TerminationRefund($outgoingPaymentId, $docNumber, $targetAccount, $amount, $pdfPath);
    }

    /** @param ?float $amount null = celý zůstatek */
    private function clearCredit(int $memberId, ?float $amount): void
    {
        $credit = $this->ledger->creditAccount($memberId);
        if (!$credit || $credit->balance == 0) {
            return;
        }
        $operating = $this->ledger->organizationAccountId(AccountAttribute::OPERATING);
        if (!$operating) {
            return;
        }

        $this->ledger->transfer((int) $credit->id, $operating, TransferType::CREDIT_CLEARING, $amount ?? (float) $credit->balance,
            'Vymazání kreditu při ukončení členství s vratkou', $memberId);
    }
}
