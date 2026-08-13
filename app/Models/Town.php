<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Town extends Model
{
    use \App\Models\Concerns\Auditable;

    public $timestamps = false;
    protected $table = 'towns';
    protected $fillable = ['town', 'quarter', 'zip_code'];

    public function streets()
    {
        return $this->hasMany(Street::class);
    }

    public function addressPoints()
    {
        return $this->hasMany(AddressPoint::class);
    }

    public function __toString(): string
    {
        if (!$this->id) return '';
        return $this->town
            . ($this->quarter ? ' - ' . $this->quarter : '')
            . ', ' . $this->zip_code;
    }
}
