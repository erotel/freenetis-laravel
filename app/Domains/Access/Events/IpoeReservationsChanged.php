<?php

namespace App\Domains\Access\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Změnily se IPoE rezervace (line_ids nebo IP rozhraní v dhcp+ipoe subnetu) —
 * RADIUS views už vrací nový stav, ale Kea drží zacachovaný verdikt hosta.
 */
class IpoeReservationsChanged
{
    use Dispatchable;

    /**
     * Výsledek flushe vyplněný listenerem FlushKeaHostCache (null = neproběhl).
     * Jediné měnitelné pole — čte ho lineid:sync pro výpis. Návratová hodnota
     * listeneru na to nejde: `false` z listeneru Laravel bere jako stop
     * propagace a do odpovědí ho nezapíše.
     */
    public ?bool $cacheFlushed = null;

    /** @param string $source odkud změna přišla (lineid:sync, ip_address.store…) — pro log */
    public function __construct(public readonly string $source)
    {
    }
}
