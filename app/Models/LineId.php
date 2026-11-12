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

    /**
     * Čitelný remote-id do UI: tisknutelný řetězec (např. MAC switche
     * "48:a9:8a:5e:58:c9" nebo vlastní "URC-171-2") vrátí tak jak je, binární
     * (netisknutelné bajty) jako "0x<hex>". Prázdný remote → null.
     */
    public function getRemoteDisplayAttribute(): ?string
    {
        $r = $this->remote_id;
        if ($r === null || $r === '') {
            return null;
        }
        return preg_match('/[^\x20-\x7e]/', $r) ? '0x' . bin2hex($r) : $r;
    }
}
