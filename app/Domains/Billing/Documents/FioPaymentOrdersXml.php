<?php

namespace App\Domains\Billing\Documents;

use App\Models\BankAccount;

/** Platební příkazy pro FIO (importIB.xsd, tuzemské): zpráva = text + „OP #id". */
final class FioPaymentOrdersXml
{
    public static function build(iterable $payments, BankAccount $bankAccount): string
    {
        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<Import xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
              . ' xsi:noNamespaceSchemaLocation="http://www.fio.cz/schema/importIB.xsd">' . "\n";
        $xml .= '<Orders>' . "\n";

        foreach ($payments as $payment) {
            $parts     = explode('/', $payment->target_account ?? '');
            $accountNr = $parts[0] ?? '';
            $bankNr    = $parts[1] ?? '';

            $xml .= '<DomesticTransaction>' . "\n";
            $xml .= '<accountFrom>' . e($bankAccount->account_nr) . '</accountFrom>' . "\n";
            $xml .= '<currency>' . e($payment->currency ?: 'CZK') . '</currency>' . "\n";
            $xml .= '<amount>' . number_format((float) $payment->amount, 2, '.', '') . '</amount>' . "\n";
            $xml .= '<accountTo>' . e($accountNr) . '</accountTo>' . "\n";
            $xml .= '<bankCode>' . e($bankNr) . '</bankCode>' . "\n";
            if ($payment->variable_symbol) {
                $xml .= '<vs>' . e($payment->variable_symbol) . '</vs>' . "\n";
            }
            $xml .= '<date>' . now()->format('Y-m-d') . '</date>' . "\n";
            $message = trim($payment->message . ' OP #' . $payment->id);
            $xml .= '<messageForRecipient>' . e(mb_substr($message, 0, 140)) . '</messageForRecipient>' . "\n";
            $xml .= '<comment>' . e(mb_substr($message, 0, 255)) . '</comment>' . "\n";
            $xml .= '<paymentType>431001</paymentType>' . "\n";
            $xml .= '</DomesticTransaction>' . "\n";
        }

        return $xml . '</Orders>' . "\n" . '</Import>' . "\n";
    }
}
