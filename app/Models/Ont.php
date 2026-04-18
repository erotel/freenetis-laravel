<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class Ont extends Model
{
    protected $table = 'onts';

    protected $fillable = [
        'serial', 'gpon_port', 'port_num', 'port_index',
        'ont_id', 'service_port', 'vlan', 'reg_status',
        'house_no', 'user_name', 'olt_ip', 'member_id', 'device_id',
    ];

    protected $casts = [
        'port_num'     => 'integer',
        'port_index'   => 'integer',
        'ont_id'       => 'integer',
        'service_port' => 'integer',
        'vlan'         => 'integer',
        'member_id'    => 'integer',
        'device_id'    => 'integer',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function scopeRegistered(Builder $query): Builder
    {
        return $query->where('reg_status', 'registered');
    }

    public function scopeNewOnts(Builder $query): Builder
    {
        return $query->where('reg_status', 'new');
    }

    public function scopeRemoved(Builder $query): Builder
    {
        return $query->where('reg_status', 'removed');
    }
}
