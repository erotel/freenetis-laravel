<?php

namespace App\Http\Controllers;

use App\Domains\Audit\Repositories\AuditTrailRepository;
use Illuminate\Http\Request;

/**
 * Globální prohlížeč audit trailu (NIS2/ZoKB) s filtry.
 *
 * Fulltext hledá v old_values/new_values (JSON) — takže najde i konkrétní
 * hodnotu, např. smazanou IP adresu „10.20.30.40". Přístup má stejná role
 * jako správa ACL (nejcitlivější admin).
 */
class AuditLogController extends Controller
{
    private const ACL = ['view_all', 'Aro_groups_Controller', 'aro_group'];

    public function index(Request $request, AuditTrailRepository $audit)
    {
        abort_unless($this->aclCheck(...self::ACL), 403);

        $q      = trim((string) $request->query('q', ''));
        $type   = trim((string) $request->query('type', ''));
        $action = trim((string) $request->query('action', ''));
        $user   = trim((string) $request->query('user', ''));
        $from   = $request->query('from');
        $to     = $request->query('to');

        $logs  = $audit->search(compact('q', 'type', 'action', 'user', 'from', 'to'), 50);
        $types = $audit->types();

        return view('audit.index', compact('logs', 'types', 'q', 'type', 'action', 'user', 'from', 'to'));
    }
}
