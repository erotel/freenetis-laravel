<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RedirectController extends Controller
{
    const ACL_SECTION = 'Redirect_Controller';
    const ACL_KEY     = 'redirect';

    // Kohana Message_Model::can_be_activate_directly() excluded types
    const EXCLUDED_TYPES = [1, 2, 3, 4, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 21, 22, 23, 24, 27, 28];

    const TYPE_LABELS = [
        0  => 'Uživatelská zpráva',
        4  => 'Přerušení členství',
        5  => 'Dlužník',
        6  => 'Upozornění na placení',
        7  => 'Nepovolené přípojné místo',
        16 => 'Expirovaný test',
        20 => 'Velký dlužník',
        25 => 'Dlužník (člen)',
        26 => 'Upozornění (člen)',
    ];

    private function canView(): bool   { return $this->aclCheck('view_all',   self::ACL_SECTION, self::ACL_KEY); }
    private function canEdit(): bool   { return $this->aclCheck('edit_all',   self::ACL_SECTION, self::ACL_KEY); }
    private function canDelete(): bool { return $this->aclCheck('delete_all', self::ACL_SECTION, self::ACL_KEY); }

    // ── Index ─────────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        abort_unless($this->canView(), 403);

        $query = DB::table('messages_ip_addresses as mia')
            ->join('ip_addresses as ip', 'ip.id', '=', 'mia.ip_address_id')
            ->join('messages as msg', 'msg.id', '=', 'mia.message_id')
            ->leftJoin('ifaces as i', 'i.id', '=', 'ip.iface_id')
            ->leftJoin('devices as d', 'd.id', '=', 'i.device_id')
            ->leftJoin('users as u', 'u.id', '=', 'd.user_id')
            ->leftJoin('members as m_user', 'm_user.id', '=', 'u.member_id')
            ->leftJoin('members as m_direct', 'm_direct.id', '=', 'ip.member_id')
            ->selectRaw('
                mia.message_id, mia.ip_address_id, mia.comment, mia.datetime,
                ip.ip_address,
                msg.name as msg_name, msg.type as msg_type,
                COALESCE(m_direct.id, m_user.id) as member_id,
                COALESCE(m_direct.name, m_user.name) as member_name
            ');

        if ($filterIp = $request->input('ip')) {
            $query->where('ip.ip_address', 'like', "%{$filterIp}%");
        }
        if ($filterMember = $request->input('member')) {
            $query->where(function ($q) use ($filterMember) {
                $q->where('m_direct.name', 'like', "%{$filterMember}%")
                  ->orWhere('m_user.name', 'like', "%{$filterMember}%");
            });
        }
        if ($filterType = $request->input('type')) {
            $query->where('msg.type', (int) $filterType);
        }

        $redirections = $query->orderByDesc('mia.datetime')->paginate(50)->withQueryString();

        return view('redirects.index', [
            'redirections' => $redirections,
            'typeLabels'   => self::TYPE_LABELS,
            'canEdit'      => $this->canEdit(),
            'canDelete'    => $this->canDelete(),
            'filterIp'     => $request->input('ip', ''),
            'filterMember' => $request->input('member', ''),
            'filterType'   => $request->input('type', ''),
        ]);
    }

    // ── Activate to member ────────────────────────────────────────────────────

    public function activateToMember(Request $request, int $memberId)
    {
        abort_unless($this->canEdit(), 403);

        $member = DB::table('members')->where('id', $memberId)->first();
        abort_if(!$member, 404);

        $messages = $this->getActivatableMessages();

        if (empty($messages)) {
            return redirect()->route('members.show', $memberId)
                ->with('warning', 'Žádná zpráva není dostupná pro přesměrování.');
        }

        if ($request->isMethod('POST')) {
            $data = $request->validate([
                'message_id' => 'required|integer|exists:messages,id',
                'comment'    => 'nullable|string|max:1000',
            ]);

            $ips = $this->getMemberIpIds($memberId);

            if ($ips->isEmpty()) {
                return redirect()->route('members.show', $memberId)
                    ->with('warning', 'Člen nemá žádné IP adresy.');
            }

            foreach ($ips as $ipId) {
                DB::table('messages_ip_addresses')->where([
                    'message_id'    => $data['message_id'],
                    'ip_address_id' => $ipId,
                ])->delete();

                DB::table('messages_ip_addresses')->insert([
                    'message_id'    => $data['message_id'],
                    'ip_address_id' => $ipId,
                    'user_id'       => auth()->id(),
                    'comment'       => $data['comment'] ?? null,
                    'datetime'      => now(),
                ]);
            }

            \App\Services\AuditLogger::log('created', 'messages_ip_addresses', $memberId, null, [
                'message_id' => $data['message_id'],
                'ip_count'   => $ips->count(),
                'scope'      => 'member',
            ]);

            return redirect()->route('members.show', $memberId)
                ->with('success', 'Přesměrování bylo aktivováno pro všechny IP adresy člena.');
        }

        return view('redirects.activate', [
            'member'   => $member,
            'ip'       => null,
            'messages' => $messages,
            'target'   => 'member',
            'formUrl'  => route('redirects.activate-member', $memberId),
            'backUrl'  => route('members.show', $memberId),
        ]);
    }

    // ── Activate to IP ────────────────────────────────────────────────────────

    public function activateToIp(Request $request, int $ipId)
    {
        abort_unless($this->canEdit(), 403);

        $ip = DB::table('ip_addresses')->where('id', $ipId)->first();
        abort_if(!$ip, 404);

        $messages = $this->getActivatableMessages();

        if ($request->isMethod('POST')) {
            $data = $request->validate([
                'message_id' => 'required|integer|exists:messages,id',
                'comment'    => 'nullable|string|max:1000',
            ]);

            DB::table('messages_ip_addresses')->where([
                'message_id'    => $data['message_id'],
                'ip_address_id' => $ipId,
            ])->delete();

            DB::table('messages_ip_addresses')->insert([
                'message_id'    => $data['message_id'],
                'ip_address_id' => $ipId,
                'user_id'       => auth()->id(),
                'comment'       => $data['comment'] ?? null,
                'datetime'      => now(),
            ]);

            \App\Services\AuditLogger::log('created', 'messages_ip_addresses', $ipId, null, [
                'message_id' => $data['message_id'],
                'scope'      => 'ip',
            ]);

            return redirect()->route('ip_addresses.show', $ipId)
                ->with('success', 'Přesměrování bylo aktivováno.');
        }

        return view('redirects.activate', [
            'member'   => null,
            'ip'       => $ip,
            'messages' => $messages,
            'target'   => 'ip',
            'formUrl'  => route('redirects.activate-ip', $ipId),
            'backUrl'  => route('ip_addresses.show', $ipId),
        ]);
    }

    // ── Delete (single IP) ────────────────────────────────────────────────────

    public function delete(int $ipId, int $msgId)
    {
        abort_unless($this->canDelete(), 403);

        DB::table('messages_ip_addresses')->where([
            'ip_address_id' => $ipId,
            'message_id'    => $msgId,
        ])->delete();

        \App\Services\AuditLogger::log('deleted', 'messages_ip_addresses', $ipId, [
            'message_id' => $msgId,
            'scope'      => 'ip',
        ], null);

        return redirect()->back()->with('success', 'Přesměrování bylo zrušeno.');
    }

    // ── Delete from member (all IPs) ──────────────────────────────────────────

    public function deleteFromMember(int $memberId, int $msgId)
    {
        abort_unless($this->canDelete(), 403);

        $ips = $this->getMemberIpIds($memberId);

        DB::table('messages_ip_addresses')
            ->whereIn('ip_address_id', $ips)
            ->where('message_id', $msgId)
            ->delete();

        \App\Services\AuditLogger::log('deleted', 'messages_ip_addresses', $memberId, [
            'message_id' => $msgId,
            'ip_count'   => $ips->count(),
            'scope'      => 'member',
        ], null);

        return redirect()->route('members.show', $memberId)
            ->with('success', 'Přesměrování bylo zrušeno pro všechny IP adresy člena.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function getActivatableMessages(): array
    {
        return DB::table('messages')
            ->whereNotIn('type', self::EXCLUDED_TYPES)
            ->whereRaw("TRIM(COALESCE(text,'')) != ''")
            ->orderBy('type')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn($m) => [$m->id => $m->name])
            ->all();
    }

    private function getMemberIpIds(int $memberId): \Illuminate\Support\Collection
    {
        return DB::table('ip_addresses as ip')
            ->leftJoin('ifaces as i', 'i.id', '=', 'ip.iface_id')
            ->leftJoin('devices as d', 'd.id', '=', 'i.device_id')
            ->leftJoin('users as u', 'u.id', '=', 'd.user_id')
            ->where(function ($q) use ($memberId) {
                $q->where('ip.member_id', $memberId)
                  ->orWhere('u.member_id', $memberId);
            })
            ->pluck('ip.id')
            ->unique();
    }

    // ── Static helpers for other controllers ──────────────────────────────────

    public static function getMemberRedirections(int $memberId)
    {
        return DB::table('messages_ip_addresses as mia')
            ->join('ip_addresses as ip', 'ip.id', '=', 'mia.ip_address_id')
            ->join('messages as msg', 'msg.id', '=', 'mia.message_id')
            ->leftJoin('ifaces as i', 'i.id', '=', 'ip.iface_id')
            ->leftJoin('devices as d', 'd.id', '=', 'i.device_id')
            ->leftJoin('users as u', 'u.id', '=', 'd.user_id')
            ->where(function ($q) use ($memberId) {
                $q->where('ip.member_id', $memberId)
                  ->orWhere('u.member_id', $memberId);
            })
            ->select('mia.message_id', 'mia.ip_address_id', 'mia.comment', 'mia.datetime',
                     'ip.ip_address', 'msg.name as msg_name', 'msg.type as msg_type')
            ->orderByDesc('mia.datetime')
            ->get()
            ->groupBy('message_id');
    }
}
