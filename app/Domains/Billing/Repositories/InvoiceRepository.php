<?php

namespace App\Domains\Billing\Repositories;

use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

/** Faktury (invoices, invoice_items) a adresáti dokladů. */
class InvoiceRepository
{
    /**
     * Další číslo faktury roku `YYYY00001…`. MAX s lockForUpdate — volat UVNITŘ
     * transakce, ať dvě souběžná vystavení nedostanou stejné číslo (UNIQUE
     * index je až poslední pojistka).
     */
    public function nextNumber(int $year): int
    {
        $min = $year * 100000;
        $max = Invoice::whereBetween('invoice_nr', [$min, $min + 99999])->lockForUpdate()->max('invoice_nr');
        return $max ? ((int) $max + 1) : ($min + 1);
    }

    /** Unikátní neprázdné e-maily (kontakt typ 20) všech uživatelů člena. @return string[] */
    public function memberEmails(int $memberId): array
    {
        return DB::table('users_contacts')
            ->join('users', 'users.id', '=', 'users_contacts.user_id')
            ->join('contacts', 'contacts.id', '=', 'users_contacts.contact_id')
            ->where('users.member_id', $memberId)
            ->where('contacts.type', 20)
            ->whereNotNull('contacts.value')
            ->where('contacts.value', '!=', '')
            ->pluck('contacts.value')
            ->unique()
            ->values()
            ->toArray();
    }
}
