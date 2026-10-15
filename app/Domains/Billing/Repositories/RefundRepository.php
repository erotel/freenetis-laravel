<?php

namespace App\Domains\Billing\Repositories;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Vratky: odchozí platby (outgoing_payments), fronta dobropisů pro Pohodu
 * (pohoda_refund_queue) a jejich číslování.
 */
class RefundRepository
{
    /** Bankovní účet organizace, ze kterého se vrací členovi daného typu (0 = nenastaveno). */
    public function bankAccountIdFor(int $memberType): int
    {
        return (int) Setting::get(self::bankAccountSetting($memberType), 0);
    }

    public static function bankAccountSetting(int $memberType): string
    {
        return 'bank_account_member_type_' . $memberType;
    }

    /** Návrh odchozí platby (status draft — odešle ji až admin). */
    public function createOutgoingPayment(int $bankAccountId, string $targetAccount, float $amount, string $message, string $reason, int $createdBy): int
    {
        return (int) DB::table('outgoing_payments')->insertGetId([
            'bank_account_id' => $bankAccountId,
            'target_account'  => $targetAccount,
            'amount'          => $amount,
            'currency'        => 'CZK',
            'message'         => $message,
            'reason'          => $reason,
            'status'          => 'draft',
            'created_by'      => $createdBy,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    /**
     * Další číslo dobropisu v aktuálním roce:
     *  - zákazník: "YY###"    (např. 26011)
     *  - člen:     "YYČL####" (např. 26ČL0010)
     *
     * Formát sám rozliší zákazníka od člena; filtr na member_type by byl navíc
     * nebezpečný (18 i jiné typy dostávají zákaznický/členský formát, ale do
     * fronty se ukládá jejich vlastní typ → duplicitní sekvence).
     *
     * lockForUpdate() drží gap-lock přes MAX-skupinu do commitu — dvě paralelní
     * ukončení nevygenerují stejné číslo. MUSÍ se volat uvnitř transakce.
     * RIGHT() místo SUBSTRING() kvůli multibyte 'Č'.
     */
    public function nextDocNumber(bool $isCustomer): string
    {
        $year2 = date('y');
        if ($isCustomer) {
            $maxSeq = (int) DB::table('pohoda_refund_queue')
                ->where('doc_number', 'like', $year2 . '%')
                ->where('doc_number', 'not like', $year2 . 'ČL%')
                ->lockForUpdate()
                ->selectRaw('MAX(CAST(RIGHT(doc_number, 3) AS UNSIGNED)) AS m')
                ->value('m');
            return $year2 . str_pad((string) ($maxSeq + 1), 3, '0', STR_PAD_LEFT);
        }

        $prefix = $year2 . 'ČL';
        $maxSeq = (int) DB::table('pohoda_refund_queue')
            ->where('doc_number', 'like', $prefix . '%')
            ->lockForUpdate()
            ->selectRaw('MAX(CAST(RIGHT(doc_number, 4) AS UNSIGNED)) AS m')
            ->value('m');
        return $prefix . str_pad((string) ($maxSeq + 1), 4, '0', STR_PAD_LEFT);
    }

    /** Dobropis do fronty exportu do Pohody (creditNote). */
    public function queueForPohoda(int $memberId, int $memberType, string $docNumber, int $outgoingPaymentId,
                                   string $refundAccount, float $amount, string $reason, string $note): void
    {
        DB::table('pohoda_refund_queue')->insert([
            'member_id'           => $memberId,
            'member_type'         => $memberType,
            'doc_number'          => $docNumber,
            'outgoing_payment_id' => $outgoingPaymentId,
            'refund_account'      => $refundAccount,
            'amount'              => $amount,
            'currency'            => 'CZK',
            'reason'              => $reason,
            'note'                => $note,
            'created_at'          => now(),
            'status'              => 'new',
        ]);
    }
}
