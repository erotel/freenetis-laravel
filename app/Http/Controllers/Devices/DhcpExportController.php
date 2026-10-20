<?php

namespace App\Http\Controllers\Devices;

use App\Domains\Access\Actions\ExportDhcpConfigAction;
use App\Domains\Access\Repositories\DhcpExportRepository;
use App\Domains\Access\Support\DhcpClientId;
use App\Domains\Network\Queries\DeviceListQuery;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

/** DHCP servery: přehled a export konfigurace (token DHCP serveru nebo právo export). */
class DhcpExportController extends Controller
{
    use DeviceAccess;

    public function index(DeviceListQuery $list)
    {
        abort_unless($this->aclCheck('view_all', 'Devices_Controller', 'devices'), 403);

        return view('devices.dhcp_servers', [
            'devices' => $list->dhcpServers(),
            'token'   => Setting::get('dhcp_api_token'),
        ]);
    }

    public function export(Request $request, int $id, string $format, DhcpExportRepository $repo, ExportDhcpConfigAction $action)
    {
        if (!in_array($format, ExportDhcpConfigAction::FORMATS)) {
            abort(404);
        }

        // Čas zachytíme PŘED načtením stavu subnetů (viz ExportDhcpConfigAction).
        $exportStartedAt = now();

        $device = $repo->deviceWithSubnets($id);
        if (!$device) {
            abort(404);
        }

        // Auth: DHCP servery se ověřují dhcp_api_tokenem, jinak přihlášený uživatel
        // s ACL view_all#export. Legacy „volání z vlastní IP zařízení" (fromDevice)
        // bylo odstraněno (audit 2026-08-18): DHCP servery už jezdí přes token a
        // IP-based větev jen otevírala cross-member únik (export vydává
        // MAC + jméno člena sousedů na subnetu komukoliv, kdo zavolá z IP zařízení).
        $token      = $request->input('token') ?? $request->bearerToken();
        $validToken = Setting::get('dhcp_api_token');
        $tokenValid = $token && $validToken && hash_equals($validToken, $token);

        if (!$tokenValid) {
            if (auth()->guest()) {
                abort(403);
            }
            abort_unless($this->aclCheck('view_all', 'Devices_Controller', 'export'), 403);
        }

        $text = $action->handle(
            $device,
            $format,
            fromDhcpServer: (bool) $tokenValid,
            forced: $request->boolean('forced'),
            client: DhcpClientId::normalize($request->input('client')),
            role: (string) $request->input('role'),
            startedAt: $exportStartedAt,
        );

        if ($text === null) {
            return response('', 204);
        }

        return response($text, 200)->header('Content-Type', 'text/plain; charset=utf-8');
    }
}
