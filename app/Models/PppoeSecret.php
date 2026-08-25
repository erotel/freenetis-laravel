<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * PPPoE credential per přípojka (iface). PK = iface_id (ne autoinkrement).
 *
 * ZÁMĚRNĚ bez Auditable: `secret` je citlivé heslo v cleartextu (kvůli RADIUS
 * CHAP/MS-CHAPv2) — nesmí se propsat do audit_logs. Vytvoření/rotaci loguje
 * generátor zvlášť (bez hesla).
 */
class PppoeSecret extends Model
{
    protected $table = 'pppoe_secrets';
    protected $primaryKey = 'iface_id';
    public $incrementing = false;
    protected $keyType = 'int';
    public $timestamps = false; // lean schéma jako pilotní tabulka na .59 (RADIUS views)

    protected $fillable = ['iface_id', 'username', 'secret', 'enabled'];
    protected $casts = ['enabled' => 'boolean'];

    public function iface()
    {
        return $this->belongsTo(Iface::class, 'iface_id');
    }
}
