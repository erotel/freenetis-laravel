<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subnet extends Model
{
    public $timestamps = false;
    protected $table = 'subnets';

    public function ipAddresses()
    {
        return $this->hasMany(IpAddress::class);
    }

    public function getLabelAttribute(): string
    {
        $label = $this->network_address . '/' . $this->netmask;
        if ($this->name) {
            $label .= ' - ' . $this->name;
        }
        return $label;
    }
}
