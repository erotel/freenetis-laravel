<?php

namespace App\Http\Controllers\Devices;

use App\Domains\Network\Documents\DeviceInventoryCsv;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** CSV inventář všech zařízení. */
class DeviceInventoryController extends Controller
{
    use DeviceAccess;

    /**
     * CSV inventář VŠECH zařízení — vše jako na detailu zařízení (info, technici,
     * rozhraní s MAC/IPv4/IPv6), BEZ PPPoE sekce. Credentialy (login/heslo/wpa2)
     * jen s příslušným právem (stejně jako detail). Streamované + chunk kvůli
     * tisícům zařízení (bounded paměť). Oddělovač ';' + UTF-8 BOM (Excel/CZ).
     */
    public function export(Request $request, DeviceInventoryCsv $csv): StreamedResponse
    {
        abort_unless($this->can('view_all'), 403);

        return $csv->download($this->can('view_all', 'login'), $this->can('view_all', 'password'));
    }
}
