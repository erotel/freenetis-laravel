<?php

namespace App\Domains\Billing\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Výběr dokladů k exportu do Pohody a jejich označení. Výběr je idempotentní:
 * vše ve stavu new do konce cílového měsíce (i zapomenuté z dřívějška); cap
 * zabrání, aby export za měsíc pochytil doklady dalšího měsíce.
 */
class PohodaExportRepository
{
    public function invoicesUpTo(string $monthEnd): Collection
    {
        return DB::table('invoices')
            ->where('pohoda_status', 'new')
            ->whereDate('date_inv', '<=', $monthEnd)
            ->orderBy('invoice_nr')
            ->get();
    }

    public function invoiceItems(int $invoiceId): Collection
    {
        return DB::table('invoice_items')->where('invoice_id', $invoiceId)->orderBy('id')->get();
    }

    /** @param int[] $ids */
    public function markInvoicesExported(array $ids): void
    {
        DB::table('invoices')->whereIn('id', $ids)->update(['pohoda_status' => 'exported', 'pohoda_exported_at' => now()]);
    }

    /** Vratky ve frontě (max 500) s adresou člena. */
    public function refundsUpTo(string $monthEnd): Collection
    {
        return DB::table('pohoda_refund_queue as q')
            ->join('members as m', 'm.id', '=', 'q.member_id')
            ->leftJoin('address_points as ap', 'ap.id', '=', 'm.address_point_id')
            ->leftJoin('streets as s', 's.id', '=', 'ap.street_id')
            ->leftJoin('towns as t', 't.id', '=', 'ap.town_id')
            ->leftJoin('countries as c', 'c.id', '=', 'ap.country_id')
            ->where('q.status', 'new')
            ->where('q.created_at', '<=', $monthEnd)
            ->orderBy('q.id')
            ->limit(500)
            ->select(
                'q.*',
                'm.name as member_name',
                'm.organization_identifier as member_ico',
                'm.vat_organization_identifier as member_dic',
                's.street as addr_street',
                'ap.street_number as addr_street_number',
                't.town as addr_town',
                't.quarter as addr_quarter',
                't.zip_code as addr_zip',
                'c.country_name as addr_country'
            )
            ->get();
    }

    /** @param int[] $ids */
    public function markRefundsExported(array $ids): void
    {
        DB::table('pohoda_refund_queue')->whereIn('id', $ids)->update(['status' => 'exported', 'exported_at' => now()]);
    }
}
