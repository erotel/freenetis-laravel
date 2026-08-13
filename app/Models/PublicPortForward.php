<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PublicPortForward extends Model
{
    use \App\Models\Concerns\Auditable;

    protected $table = 'public_port_forwards';

    public $timestamps = false;

    protected $fillable = [
        'public_ip', 'public_port_from', 'public_port_to',
        'private_ip', 'private_port_from', 'private_port_to',
        'protocol', 'enabled',
        'created', 'modified', 'created_by', 'modified_by',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];
}
