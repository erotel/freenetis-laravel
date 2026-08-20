<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    use \App\Models\Concerns\Auditable;
    // Položky faktur vytvářené cronem se neauditují; lidská úprava ano.
    use \App\Models\Concerns\AuditsHumanActionsOnly;

    public $timestamps = false;
    protected $table = 'invoice_items';
    protected $fillable = [
        'invoice_id', 'name', 'code', 'quantity', 'price', 'vat',
        'author_fee', 'contractual_increase', 'service',
    ];

    public function invoice() { return $this->belongsTo(Invoice::class); }

    public function getPriceVatAttribute(): float
    {
        return $this->price * $this->quantity * (1 + $this->vat);
    }
}
