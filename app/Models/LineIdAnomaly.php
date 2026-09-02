<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * MAC-anomaly u IPoE line-id (přehození portů / cizí MAC na portu). Viz
 * [[project_pppoe_wpa2_nis2]] fáze B. Plněno LineIdSyncService::detectAnomalies.
 */
class LineIdAnomaly extends Model
{
    protected $table = 'line_id_anomalies';

    protected $fillable = [
        'circuit_id', 'expected_iface_id', 'seen_mac', 'seen_iface_id',
        'type', 'severity', 'seen_count', 'first_seen', 'last_seen', 'resolved_at',
    ];

    protected $casts = [
        'expected_iface_id' => 'integer',
        'seen_iface_id'     => 'integer',
        'seen_count'        => 'integer',
        'first_seen'        => 'datetime',
        'last_seen'         => 'datetime',
        'resolved_at'       => 'datetime',
    ];

    public function expectedIface()
    {
        return $this->belongsTo(Iface::class, 'expected_iface_id');
    }

    public function seenIface()
    {
        return $this->belongsTo(Iface::class, 'seen_iface_id');
    }
}
