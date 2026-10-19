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

    /** Přehled faktur, nejnovější první; filtry člen / typ / stav Pohody, hledání. */
    public function search(?int $memberId, ?int $type, ?string $pohodaStatus, string $q, int $perPage)
    {
        $query = Invoice::with(['member', 'items'])
            ->orderBy('date_inv', 'desc')
            ->orderBy('id', 'desc');

        if ($memberId) {
            $query->where('member_id', $memberId);
        }
        if ($type !== null) {
            $query->where('invoice_type', $type);
        }
        if ($pohodaStatus !== null) {
            $query->where('pohoda_status', $pohodaStatus);
        }

        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $query->where(function ($w) use ($like, $q) {
                $w->where('partner_name',    'like', $like)
                  ->orWhere('partner_company', 'like', $like)
                  ->orWhere('note',           'like', $like)
                  ->orWhereHas('member', fn ($m) => $m->where('name', 'like', $like));
                if (ctype_digit($q)) {
                    $n = (int) $q;
                    $w->orWhere('id', $n)
                      ->orWhere('invoice_nr', $n)
                      ->orWhere('member_id',  $n)
                      ->orWhere('var_sym',    $n);
                }
            });
        }

        return $query->paginate($perPage)->withQueryString();
    }

    public function ofMember(int $memberId, int $perPage)
    {
        return Invoice::with('items')
            ->where('member_id', $memberId)
            ->orderBy('date_inv', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findOrFail(int $id): Invoice
    {
        return Invoice::findOrFail($id);
    }

    public function findWithItemsOrFail(int $id): Invoice
    {
        return Invoice::with(['member', 'items'])->findOrFail($id);
    }
}
