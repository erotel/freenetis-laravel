<?php

namespace App\Domains\Notifications\Support;

use App\Domains\Billing\Documents\PaymentQrService;
use App\Domains\Billing\Repositories\LedgerRepository;
use App\Domains\Members\Repositories\MemberRepository;

/**
 * Standardní placeholdery zpráv pro člena ({member_name}, {balance},
 * {variable_symbol}, {account_number}, {iban}, {payment_amount} …).
 * Dosazení do šablony: Message::substitute().
 */
class MemberPlaceholders
{
    public function __construct(
        private MemberRepository $members,
        private LedgerRepository $ledger,
        private PaymentQrService $payments,
    ) {
    }

    /** $extra má přednost před automatickými hodnotami. */
    public function forMember(int $memberId, array $extra = []): array
    {
        $member = $this->members->row($memberId);
        if (!$member) return $extra;

        $account = $this->ledger->creditAccount($memberId);

        // VS visí na účtu přes variable_symbols (jako v Kohaně) — typicky 1,
        // víc symbolů se spojí čárkou.
        $variableSymbol = $account ? implode(',', $this->ledger->variableSymbols((int) $account->id)) : '';

        // Platební údaje (číslo účtu / IBAN / částka dle tarifu) — stejný zdroj
        // jako QR platba, aby text a QR v upozornění seděly. {payment_qr}
        // (obrázek) se řeší při odeslání (cid příloha).
        $payment = $this->payments->paymentInfoForMember($memberId);

        return array_merge([
            'member_name'     => $member->name ?? '',
            'member_id'       => $member->id,
            'leaving_date'    => $member->leaving_date ?? '',
            'entrance_date'   => $member->entrance_date ?? '',
            'balance'         => number_format((float) ($account->balance ?? 0), 2, ',', ' '),
            'variable_symbol' => $variableSymbol,
            'account_number'  => $payment['account_number'] ?? '',
            'iban'            => $payment['iban'] ?? '',
            'payment_amount'  => $payment ? number_format($payment['amount'], 2, ',', ' ') : '',
        ], $extra);
    }
}
