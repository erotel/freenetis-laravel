<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    public function index(Request $request)
    {
        abort_unless($this->aclCheck(...self::ACL), 403);

        $q      = trim((string) $request->query('q', ''));
        $type   = trim((string) $request->query('type', ''));
        $action = trim((string) $request->query('action', ''));
        $user   = trim((string) $request->query('user', ''));
        $from   = $request->query('from');
        $to     = $request->query('to');

        $query = DB::table('audit_logs as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->selectRaw("a.*, TRIM(CONCAT(COALESCE(u.surname,''),' ',COALESCE(u.name,''))) as actor_name, u.login as actor_login");

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('a.old_values', 'like', "%{$q}%")
                  ->orWhere('a.new_values', 'like', "%{$q}%");
                if (ctype_digit($q)) {
                    $w->orWhere('a.auditable_id', (int) $q);
                }
            });
        }
        if ($type !== '') {
            $query->where('a.auditable_type', $type);
        }
        if ($action !== '') {
            $query->where('a.action', $action);
        }
        if ($user !== '') {
            if (ctype_digit($user)) {
                $query->where('a.user_id', (int) $user);
            } else {
                $query->where('u.login', 'like', "%{$user}%");
            }
        }
        // Filtr na occurred_at zároveň umožní partition pruning.
        if ($from) {
            $query->where('a.occurred_at', '>=', $from . ' 00:00:00');
        }
        if ($to) {
            $query->where('a.occurred_at', '<=', $to . ' 23:59:59');
        }

        $logs = $query->orderByDesc('a.id')->paginate(50)->withQueryString();

        // Typy entit pro select (audit_logs je zatím malá; kdyby narostla,
        // lze nahradit statickým seznamem).
        $types = DB::table('audit_logs')->select('auditable_type')->distinct()
            ->orderBy('auditable_type')->pluck('auditable_type');

        return view('audit.index', compact('logs', 'types', 'q', 'type', 'action', 'user', 'from', 'to'));
    }
}
