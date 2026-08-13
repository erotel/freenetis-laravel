<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnumType extends Model
{
    use \App\Models\Concerns\Auditable;

    public $timestamps = false;
    protected $table = 'enum_types';
    protected $fillable = ['type_id', 'value', 'deprecated'];

    protected $casts = [
        'deprecated' => 'bool',
        'read_only'  => 'bool',
    ];

    /** type_id grouping all contact types */
    const CONTACT_GROUP_ID = 4;

    /** type_id grouping all device types */
    const DEVICE_GROUP_ID = 2;

    public function typeName(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(EnumTypeName::class, 'type_id');
    }

    /** Skryje hodnoty označené jako zastaralé — pro výběrové dropdowny při
     *  vytváření nových záznamů. Existující záznamy s deprecated typem se
     *  i nadále zobrazují v listingu (deprecated řeší jen nový vstup). */
    public function scopeNotDeprecated(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        return $q->where('deprecated', 0);
    }
}
