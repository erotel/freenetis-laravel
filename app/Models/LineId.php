<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * IPoE line-id → iface mapování (option82 circuit-id). Zdroj pro RADIUS views
 * radreply/radcheck_lineid_v. Viz [[project_pppoe_wpa2_nis2]] fáze B.
 */
class LineId extends Model
{
    protected $table = 'line_ids';

    protected $fillable = [
        'circuit_id', 'remote_id', 'iface_id',
        'vendor', 'device_ident', 'port', 'source', 'last_seen',
    ];

    protected $casts = [
        'iface_id'  => 'integer',
        'last_seen' => 'datetime',
    ];

    public function iface()
    {
        return $this->belongsTo(Iface::class, 'iface_id');
    }
}
