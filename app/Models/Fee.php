<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Fee extends Model
{
    use \App\Models\Concerns\Auditable;

    public $timestamps = false;
    protected $table = 'fees';
    protected $fillable = ['readonly', 'archived', 'fee', 'from', 'to', 'type_id', 'name', 'special_type_id'];

    protected $casts = [
        'readonly' => 'boolean',
        'archived' => 'boolean',
        'fee'      => 'float',
        'from'     => 'date',
        'to'       => 'date',
    ];

    // Fee type IDs (stored in enum_types)
    const TYPE_REGULAR  = 35; // regular member fee
    const TYPE_ENTRANCE = 36; // entrance fee
    const TYPE_TRANSFER = 37; // transfer fee
    // Typ 39 byl legacy „penalty" (Pokuta) — nevyužíváme. Recyklováno na
    // „Dodatečné služby" (např. poplatek za veřejnou IP), které se strhávají
    // měsíčně jako samostatná položka (transfer type 6). Enum_types.value
    // přejmenováno na 'additional service' migrací.
    const TYPE_ADDITIONAL_SERVICE = 39; // additional service (dříve penalty)

    public static function typeLabels(): array
    {
        return [
            self::TYPE_REGULAR            => 'Pravidelný poplatek',
            self::TYPE_ENTRANCE           => 'Vstupní poplatek',
            self::TYPE_TRANSFER           => 'Poplatek za převod',
            self::TYPE_ADDITIONAL_SERVICE => 'Dodatečné služby',
        ];
    }

    // Systémové poplatky klíčované přes special_type_id (viz MembershipInterruptController atd.)
    const SPECIAL_INTERRUPT = 1; // Přerušení členství (spravuje se vlastní funkcí)
    const SPECIAL_FEE_FREE  = 2; // Osvobozen od poplatku (jde přiřadit ručně)

    public function enumType()
    {
        return $this->belongsTo(EnumType::class, 'type_id');
    }

    /**
     * Poplatky nabízené při ručním přiřazení členovi:
     * běžné (nesystémové) + „Osvobozen od poplatku", vždy jen nearchivované.
     * Systémové poplatky spravované vlastní funkcí (přerušení členství) sem
     * záměrně nepatří. `readonly` slouží jen jako ochrana proti editaci/smazání,
     * ne jako zákaz přiřazení — proto ho tady nefiltrujeme přímo.
     */
    public function scopeAssignable($query)
    {
        return $query->where('archived', false)
            ->where(function ($q) {
                $q->where('readonly', false)
                  ->orWhere('special_type_id', self::SPECIAL_FEE_FREE);
            });
    }

    public function memberFees()
    {
        return $this->hasMany(MemberFee::class);
    }

    /** Počet aktivních přiřazení tarifu členům k danému dni. */
    public function activeAssignmentsCount(?string $date = null): int
    {
        $date = $date ?: now()->toDateString();

        return $this->memberFees()
            ->where('activation_date', '<=', $date)
            ->where('deactivation_date', '>=', $date)
            ->count();
    }

    /** Má tarif k danému dni aspoň jedno aktivní přiřazení členovi? */
    public function hasActiveAssignments(?string $date = null): bool
    {
        return $this->activeAssignmentsCount($date) > 0;
    }
}
