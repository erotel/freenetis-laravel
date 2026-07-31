<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Fee extends Model
{
    public $timestamps = false;
    protected $table = 'fees';
    protected $fillable = ['readonly', 'fee', 'from', 'to', 'type_id', 'name', 'special_type_id'];

    protected $casts = [
        'readonly' => 'boolean',
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

    public function enumType()
    {
        return $this->belongsTo(EnumType::class, 'type_id');
    }

    public function memberFees()
    {
        return $this->hasMany(MemberFee::class);
    }
}
