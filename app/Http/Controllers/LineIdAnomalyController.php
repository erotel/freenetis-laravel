<?php

namespace App\Http\Controllers;

use App\Models\LineIdAnomaly;
use Illuminate\Http\Request;

/**
 * Přehled MAC-anomalií IPoE line-id (přehození portů / cizí MAC na portu).
 * Fáze B — viz [[project_pppoe_wpa2_nis2]]. Práva jako DHCP na subnetech.
 */
class LineIdAnomalyController extends Controller
{
    private const ACL_SECTION = 'Subnets_Controller';
    private const ACL_VALUE   = 'dhcp';

    private function can(string $action): bool
    {
        return $this->aclCheck($action, self::ACL_SECTION, self::ACL_VALUE);
    }

    public function index(Request $request)
    {
        abort_unless($this->can('view_all'), 403);

        $severity     = (string) $request->input('severity', '');
        $showResolved = $request->boolean('resolved');

        $items = LineIdAnomaly::with([
                'expectedIface.device.user.member',
                'seenIface.device.user.member',
            ])
            ->when(!$showResolved, fn ($q) => $q->whereNull('resolved_at'))
            ->when($severity !== '', fn ($q) => $q->where('severity', $severity))
            ->orderByRaw("FIELD(severity,'critical','high','warning')")
            ->orderByDesc('last_seen')
            ->paginate(50)
            ->withQueryString();

        return view('line_id_anomalies.index', [
            'items'        => $items,
            'severity'     => $severity,
            'showResolved' => $showResolved,
            'canResolve'   => $this->can('edit_all'),
            'openCritical' => LineIdAnomaly::whereNull('resolved_at')->where('severity', 'critical')->count(),
        ]);
    }

    public function resolve(int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        LineIdAnomaly::whereKey($id)->update(['resolved_at' => now()]);

        return back()->with('success', 'Anomálie označena jako vyřešená.');
    }
}
