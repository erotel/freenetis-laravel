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

    /**
     * Účet, na který vrátit přeplatek (předvyplnění formuláře Ukončit):
     *  1) poslední bankovní platba, která reálně přistála na kreditu PRÁVĚ
     *     tohoto člena (přeposlání → previous_transfer → bank_transfers.origin) —
     *     pokrývá plátce s více záznamy v bank_accounts;
     *  2) jinak nejnovější vyplněný bank_accounts záznam člena;
     *  3) jinak '' (admin vyplní ručně).
     */
    public function suggestedRefundAccount(int $memberId): string
    {
        $lastPayer = DB::table('accounts AS a')
            ->join('transfers AS t2',      't2.destination_id', '=', 'a.id')
            ->join('transfers AS t1',      't1.id', '=', 't2.previous_transfer_id')
            ->join('bank_transfers AS bt', 'bt.transfer_id', '=', 't1.id')
            ->join('bank_accounts AS ba',  'ba.id', '=', 'bt.origin_id')
            ->whereNull('bt.deleted_at')
            ->where('a.member_id', $memberId)
            ->where('a.account_attribute_id', 221100)
            ->whereNotNull('ba.account_nr')
            ->whereNotNull('ba.bank_nr')
            ->where('ba.account_nr', '!=', '')
            ->where('ba.bank_nr',    '!=', '')
            ->orderByDesc('t2.datetime')
            ->first(['ba.account_nr', 'ba.bank_nr']);

        $bankAccount = $lastPayer ?: DB::table('bank_accounts')
            ->where('member_id', $memberId)
            ->whereNotNull('account_nr')
            ->whereNotNull('bank_nr')
            ->where('account_nr', '!=', '')
            ->where('bank_nr', '!=', '')
            ->orderByDesc('id')
            ->first(['account_nr', 'bank_nr']);

        return $bankAccount ? trim($bankAccount->account_nr) . '/' . trim($bankAccount->bank_nr) : '';
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

    /** Fronta vratek k exportu do Pohody, nejnovější první; volitelně podle stavu. */
    public function pohodaQueue(string $status, int $perPage)
    {
        return DB::table('pohoda_refund_queue as q')
            ->join('members as m', 'm.id', '=', 'q.member_id')
            ->select(
                'q.id', 'q.member_id', 'q.doc_number', 'q.refund_account',
                'q.amount', 'q.currency', 'q.reason', 'q.note',
                'q.status', 'q.created_at', 'q.exported_at',
                'm.name as member_name'
            )
            ->when($status !== '', fn ($q) => $q->where('q.status', $status))
            ->orderByDesc('q.id')
            ->paginate($perPage)->withQueryString();
    }
}
