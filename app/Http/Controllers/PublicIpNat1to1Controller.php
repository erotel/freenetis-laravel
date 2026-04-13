<?php

namespace App\Http\Controllers;

use App\Models\PublicIpNat1to1;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
class PublicIpNat1to1Controller extends Controller
{
    private function can(string $action = 'view_all'): bool
    {
        return $this->aclCheck($action, 'Network_Controller', 'public_ip_nat');
    }

    public function index(Request $request)
    {
        abort_unless($this->can(), 403);

        $enabled = $request->query('enabled', 'all');
        $q       = trim((string) $request->query('q', ''));

        $query = DB::table('public_ip_nat_1to1 AS n')
            ->leftJoin('ip_addresses AS ip', DB::raw('ip.ip_address COLLATE utf8mb3_uca1400_ai_ci'), '=', 'n.private_ip')
            ->leftJoin('ifaces AS i', 'i.id', '=', 'ip.iface_id')
            ->leftJoin('devices AS d', 'd.id', '=', 'i.device_id')
            ->leftJoin('users AS u', 'u.id', '=', 'd.user_id')
            ->leftJoin('members AS om', 'om.id', '=', 'u.member_id')
            ->leftJoin('members AS mm', 'mm.id', '=', 'n.modified_by')
            ->select(
                'n.id', 'n.public_ip', 'n.private_ip', 'n.scope',
                'n.enabled', 'n.comment', 'n.created', 'n.modified',
                'om.name AS owner_member_name',
                'mm.name AS modified_by_name'
            )
            ->orderByRaw('n.enabled DESC, INET_ATON(n.public_ip) ASC');

        if ($enabled === '1' || $enabled === '0') {
            $query->where('n.enabled', (int) $enabled);
        }

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('n.public_ip', 'like', "%{$q}%")
                    ->orWhere('n.private_ip', 'like', "%{$q}%")
                    ->orWhere('om.name', 'like', "%{$q}%");
            });
        }

        $rows = $query->get();

        return view('public_ip_nat1to1.index', [
            'rows'    => $rows,
            'enabled' => $enabled,
            'q'       => $q,
            'canEdit' => $this->can('edit_all'),
        ]);
    }

    public function edit(int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $record = PublicIpNat1to1::findOrFail($id);

        return view('public_ip_nat1to1.form', [
            'action' => 'edit',
            'record' => $record,
        ]);
    }

    public function update(Request $request, int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $record = PublicIpNat1to1::findOrFail($id);

        $data = $request->validate([
            'private_ip' => ['nullable', 'ip'],
            'enabled'    => ['nullable', 'boolean'],
        ], [
            'private_ip.ip' => 'Neplatná privátní IPv4 adresa.',
        ]);

        $privateIp = $data['private_ip'] ?? null;
        $enabled   = $request->boolean('enabled') ? 1 : 0;

        if ($privateIp !== null && $privateIp === $record->public_ip) {
            throw ValidationException::withMessages([
                'private_ip' => 'Veřejná a privátní IP nesmějí být stejné.',
            ]);
        }

        $memberId = Auth::user()?->member_id;

        $record->update([
            'private_ip'  => $privateIp,
            'enabled'     => $enabled,
            'modified'    => now(),
            'modified_by' => $memberId,
        ]);

        session()->flash('success', 'Záznam byl uložen.');
        return redirect()->route('public-ip-nat.index');
    }

    public function toggle(int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $record   = PublicIpNat1to1::findOrFail($id);
        $memberId = Auth::user()?->member_id;

        $record->update([
            'enabled'     => $record->enabled ? 0 : 1,
            'modified'    => now(),
            'modified_by' => $memberId,
        ]);

        return redirect()->route('public-ip-nat.index');
    }

    public function clear(int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $record   = PublicIpNat1to1::findOrFail($id);
        $memberId = Auth::user()?->member_id;

        $record->update([
            'private_ip'  => null,
            'modified'    => now(),
            'modified_by' => $memberId,
        ]);

        session()->flash('success', 'Mapování bylo vymazáno.');
        return redirect()->route('public-ip-nat.index');
    }

}
