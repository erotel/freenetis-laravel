<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PublicIpNat1to1 extends Model
{
    use \App\Models\Concerns\Auditable;

    protected $table = 'public_ip_nat_1to1';

    public $timestamps = false;

    protected $fillable = [
        'public_ip', 'private_ip', 'scope', 'enabled',
        'comment', 'created', 'modified', 'created_by', 'modified_by',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];
}
