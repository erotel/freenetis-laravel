<?php

namespace App\Domains\Notifications\Queries;

use App\Models\Message;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Příjemci automatického upozornění podle typu zprávy (read model): aktivní
 * členové s kreditním účtem (mimo sdružení, s datem vstupu, bez odchodu),
 * po imunitě nových, s e-mailem / telefonem. Dlužník (5 zákazník / 25 člen) =
 * kredit pod hranicí NEBO payment_blocked; upomínka (6 / 26) = kredit nepokryje
 * příští měsíční srážku (mirror FeePolicy).
 */
class NotificationRecipientsQuery
{
    public function forMessageType(int $messageType, float $debtorBoundary, float $bigDebtorBoundary, int $immunity): \Illuminate\Support\Collection
    {
        $date = now()->format('Y-m-d');

        // Base query - člen s kreditním účtem
        $base = DB::table('members as m')
            ->join('accounts as a', function ($j) {
                $j->on('a.member_id', '=', 'm.id')
                  ->where('a.account_attribute_id', 221100);
            })
            ->leftJoin('users as u', 'u.member_id', '=', 'm.id')
            ->leftJoin('users_contacts as uc', 'uc.user_id', '=', 'u.id')
            ->leftJoin('contacts as c', function ($j) {
                $j->on('c.id', '=', 'uc.contact_id')
                  ->whereIn('c.type', [20, 21]); // 20=email, 21=phone
            })
            ->where('m.id', '!=', 1)
            ->where('m.entrance_date', '!=', '0000-00-00')
            ->whereNotNull('m.entrance_date')
            ->where(function ($q) use ($date) {
                $q->where('m.leaving_date', '0000-00-00')
                  ->orWhere('m.leaving_date', '9999-12-31')
                  ->orWhere('m.leaving_date', '>', $date);
            });

        // Imunita nových členů
        if ($immunity > 0) {
            $base->where('m.entrance_date', '<', now()->subDays($immunity)->format('Y-m-d'));
        }

        switch ($messageType) {
            case Message::DEBTOR_MESSAGE: // type 5 - zákazník 'Nedostatečná výše konta'
                // Prepaid: zahrň i payment_blocked=1 i kdyby balance ≥ 0
                // (DeductFees nestrhl, takže technicky není v mínusu, ale stále
                // nemá dostatek kreditu na další měsíc).
                return $base
                    ->where('m.type', 2)
                    ->where(function ($q) use ($debtorBoundary) {
                        $q->where('a.balance', '<', $debtorBoundary)
                          ->orWhere('m.payment_blocked', 1);
                    })
                    ->select(
                        'm.id', 'm.name',
                        DB::raw('MAX(CASE WHEN c.type = 20 THEN c.value END) as email'),
                        DB::raw('MAX(CASE WHEN c.type = 21 THEN c.value END) as phone')
                    )
                    ->groupBy('m.id', 'm.name', 'a.balance')
                    ->get();

            case Message::PAYMENT_NOTICE_MESSAGE: // type 6 - zákazník upomínka (balance < tarif)
                return $base
                    ->where('m.type', 2)
                    ->leftJoin('speed_classes as sc', 'sc.id', '=', 'm.speed_class_id')
                    ->whereRaw('a.balance < ' . self::MONTHLY_CHARGE_SQL, $this->monthlyChargeBindings(2, $date))
                    ->select(
                        'm.id', 'm.name',
                        DB::raw('MAX(CASE WHEN c.type = 20 THEN c.value END) as email'),
                        DB::raw('MAX(CASE WHEN c.type = 21 THEN c.value END) as phone')
                    )
                    ->groupBy('m.id', 'm.name', 'a.balance')
                    ->get();

            case Message::DEBTOR_MESSAGE_CLEN: // type 25 - člen 'Nedostatečná výše konta'
                return $base
                    ->where('m.type', 90)
                    ->where(function ($q) use ($debtorBoundary) {
                        $q->where('a.balance', '<', $debtorBoundary)
                          ->orWhere('m.payment_blocked', 1);
                    })
                    ->select(
                        'm.id', 'm.name',
                        DB::raw('MAX(CASE WHEN c.type = 20 THEN c.value END) as email'),
                        DB::raw('MAX(CASE WHEN c.type = 21 THEN c.value END) as phone')
                    )
                    ->groupBy('m.id', 'm.name', 'a.balance')
                    ->get();

            case Message::PAYMENT_NOTICE_MESSAGE_CLEN: // type 26 - člen upomínka
                return $base
                    ->where('m.type', 90)
                    ->leftJoin('speed_classes as sc', 'sc.id', '=', 'm.speed_class_id')
                    ->whereRaw('a.balance < ' . self::MONTHLY_CHARGE_SQL, $this->monthlyChargeBindings(90, $date))
                    ->select(
                        'm.id', 'm.name',
                        DB::raw('MAX(CASE WHEN c.type = 20 THEN c.value END) as email'),
                        DB::raw('MAX(CASE WHEN c.type = 21 THEN c.value END) as phone')
                    )
                    ->groupBy('m.id', 'm.name', 'a.balance')
                    ->get();

            default:
                return collect();
        }
    }

    /**
     * Setting default_fee_member_type_{2|90} drží fee_id (FK do fees), ne částku.
     * Vrací skutečnou částku z fees.fee podle uložené id, jinak 0.
     */
    /**
     * Co se příští srážkou skutečně strhne (mirror FeePolicy a LedgerRepository):
     * pravidelný poplatek = individuální 'regular member fee' s nejnižší
     * prioritou → cena tarifu → základní dle typu; + příplatky = dodatečné
     * služby + placená přípojná místa (transfer type 6). Dřív se připojoval
     * JAKÝKOLI aktivní members_fees bez priority (víc individuálních → práh
     * podle nejvyššího; služba nahradila tarif) — D3, 2026-10-15.
     */
    private const MONTHLY_CHARGE_SQL = "(
        COALESCE(
            (SELECT f.fee FROM members_fees mf
               JOIN fees f ON f.id = mf.fee_id
               JOIN enum_types et ON et.id = f.type_id
              WHERE LOWER(et.value) = 'regular member fee'
                AND mf.member_id = m.id AND mf.activation_date <= ? AND mf.deactivation_date >= ?
              ORDER BY mf.priority LIMIT 1),
            sc.price, ?, 0)
        + (SELECT COALESCE(SUM(f.fee), 0) FROM members_fees mf
             JOIN fees f ON f.id = mf.fee_id
             JOIN enum_types et ON et.id = f.type_id
            WHERE LOWER(et.value) = 'additional service'
              AND mf.member_id = m.id AND mf.activation_date <= ? AND mf.deactivation_date >= ?)
        + (SELECT COALESCE(SUM(COALESCE(scp.price, 0)), 0) FROM allowed_subnets asub
             JOIN speed_classes scp ON scp.id = asub.speed_class_id
            WHERE asub.member_id = m.id AND asub.charged = 1)
    )";

    private function monthlyChargeBindings(int $memberType, string $date): array
    {
        return [$date, $date, $this->defaultFeeAmount($memberType), $date, $date];
    }

    private function defaultFeeAmount(int $memberType): float
    {
        $feeId = (int) Setting::get("default_fee_member_type_{$memberType}", 0);
        if ($feeId <= 0) return 0.0;
        return (float) (DB::table('fees')->where('id', $feeId)->value('fee') ?? 0);
    }
}
