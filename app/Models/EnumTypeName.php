<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnumTypeName extends Model
{
    use \App\Models\Concerns\Auditable;

    public $timestamps = false;
    protected $table = 'enum_type_names';
    protected $fillable = ['type_name'];

    public function enumTypes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EnumType::class, 'type_id');
    }
}
