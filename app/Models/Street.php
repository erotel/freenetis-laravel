<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Street extends Model
{
    use \App\Models\Concerns\Auditable;

    public $timestamps = false;
    protected $table = 'streets';
    protected $fillable = ['street', 'town_id'];

    public function town()
    {
        return $this->belongsTo(Town::class);
    }

    public function addressPoints()
    {
        return $this->hasMany(\App\Models\AddressPoint::class);
    }

    public function __toString(): string
    {
        return $this->street ?? '';
    }
}
