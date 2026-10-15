<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Fees\FeePolicy;
use App\Domains\Billing\Repositories\LedgerRepository;
use App\Domains\Billing\Support\AccountAttribute;
use App\Domains\Billing\Support\TransferType;
use App\Services\AuditLogger;

/**
 * Jednorázová srážka pravidelného měsíčního poplatku k datu (obnovení
 * přerušeného členství). Stejná pravidla jako měsíční srážka: poplatek dle
 * FeePolicy, cíl provozní účet 221101 + přepočet jeho zůstatku, prepaid —
 * při nedostatku kreditu nestrhnout, jen payment_blocked (doplatek doběhne
 * PaymentBackchargeService po další platbě). Idempotence: převod type 1
 * s `datetime` = datum už existuje → nic (srážka v deduct_day pak také ne).
 *
 * Volat uvnitř transakce.
 */
class ChargeMonthlyFeeNowAction
{
    public const CHARGED         = 'charged';
    public const SKIPPED_BLOCKED = 'skipped_blocked';
    public const NOOP            = 'noop';   // nulový poplatek, chybí účet, nebo už strženo

    private const TEXT = 'Doplatek za měsíc po obnovení přerušení';

    public function __construct(
        private FeePolicy $fees,
        private LedgerRepository $ledger,
    ) {
    }

    /** @return string self::CHARGED | self::SKIPPED_BLOCKED | self::NOOP */
    public function handle(int $memberId, int $memberType, string $date, ?int $userId): string
    {
        $amount = (float) ($this->fees->regularFee($memberId, $memberType, $date) ?? 0.0);
        if ($amount <= 0) return self::NOOP;

        $credit = $this->ledger->creditAccount($memberId);
        if (!$credit) return self::NOOP;

        $operating = $this->ledger->organizationAccountId(AccountAttribute::OPERATING);
        if (!$operating) return self::NOOP;

        if ($this->ledger->hasTransferAt((int) $credit->id, TransferType::MEMBER_FEE, $date)) return self::NOOP;

        if ((float) $credit->balance < $amount) {
            $this->ledger->blockForPayment($memberId, $date);
            return self::SKIPPED_BLOCKED;
        }

        $this->ledger->postFee((int) $credit->id, $operating, TransferType::MEMBER_FEE, $amount, $date,
            now()->format('Y-m-d H:i:s'), self::TEXT, $userId);
        $this->ledger->recalculateBalance($operating);
        AuditLogger::log('created', 'transfers', null, null, [
            'member_id' => $memberId,
            'amount'    => $amount,
            'type'      => TransferType::MEMBER_FEE,
            'text'      => self::TEXT,
        ]);

        return self::CHARGED;
    }
}
