<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AddressPoint extends Model
{
    use \App\Models\Concerns\Auditable;

    public $timestamps = false;
    protected $table = 'address_points';
    protected $fillable = ['name', 'country_id', 'town_id', 'street_id', 'street_number'];

    public function town()
    {
        return $this->belongsTo(Town::class);
    }

    public function street()
    {
        return $this->belongsTo(Street::class);
    }
}
