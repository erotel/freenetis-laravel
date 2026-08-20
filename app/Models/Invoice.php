<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use \App\Models\Concerns\Auditable;

    /**
     * Audit jen lidských zásahů. Automatické vystavení faktur cronem (systém,
     * bez přihlášeného uživatele) do audit logu nepíšeme — faktura sama je
     * účetní záznam (tabulka + PDF, archivace dle z. o účetnictví) a hromadné
     * cron řádky by jen zahltily audit_logs. Lidská úprava/smazání vystavené
     * faktury (tamper/fraud) se loguje dál.
     */
    public function auditShouldSkip(string $action): bool
    {
        return !auth()->check();
    }

    public $timestamps = false;
    protected $table = 'invoices';
    protected $fillable = [
        'member_id', 'invoice_nr', 'invoice_type', 'account_nr',
        'var_sym', 'con_sym', 'date_inv', 'date_due', 'date_vat', 'vat', 'currency', 'note',
        'pdf_filename', 'partner_name', 'partner_street', 'partner_street_number',
        'partner_town', 'partner_zip_code', 'partner_country', 'partner_company',
        'organization_identifier', 'vat_organization_identifier', 'phone_number', 'email',
        'order_nr',
    ];

    const TYPE_ISSUED   = 0;
    const TYPE_RECEIVED = 1;

    public function member() { return $this->belongsTo(Member::class); }
    public function items()  { return $this->hasMany(InvoiceItem::class); }

    public function getPriceTotalAttribute(): float
    {
        return $this->items->sum(fn($i) => $i->price * $i->quantity);
    }

    public function getPriceVatTotalAttribute(): float
    {
        return $this->items->sum(fn($i) => $i->price * $i->quantity * (1 + $i->vat));
    }

    public function getTypeLabel(): string
    {
        return $this->invoice_type == self::TYPE_ISSUED ? 'Vydaná' : 'Přijatá';
    }
}
