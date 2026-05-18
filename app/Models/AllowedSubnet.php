<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AllowedSubnet extends Model
{
    public $timestamps = false;
    protected $table = 'allowed_subnets';
    protected $fillable = ['member_id', 'subnet_id', 'enabled', 'last_update'];
    protected $casts = ['enabled' => 'boolean'];

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function subnet()
    {
        return $this->belongsTo(Subnet::class);
    }
}
