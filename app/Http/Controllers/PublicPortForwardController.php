<?php

namespace App\Http\Controllers;

use App\Models\PublicIp;
use App\Models\PublicPortForward;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PublicPortForwardController extends Controller
{
    private function can(string $action = 'view_all'): bool
    {
        return $this->aclCheck($action, 'Network_Controller', 'public_ports');
    }

    public function index()
    {
        abort_unless($this->can(), 403);

        $rows = DB::table('public_port_forwards AS p')
            ->leftJoin('ip_addresses AS ip', DB::raw('ip.ip_address COLLATE utf8mb3_uca1400_ai_ci'), '=', 'p.private_ip')
            ->leftJoin('ifaces AS i', 'i.id', '=', 'ip.iface_id')
            ->leftJoin('devices AS d', 'd.id', '=', 'i.device_id')
            ->leftJoin('users AS u', 'u.id', '=', 'd.user_id')
            ->leftJoin('members AS om', 'om.id', '=', 'u.member_id')
            ->select(
                'p.id', 'p.public_ip', 'p.public_port_from', 'p.public_port_to',
                'p.private_ip', 'p.private_port_from', 'p.private_port_to',
                'p.protocol', 'p.enabled', 'p.created', 'p.modified',
                'om.name AS owner_member_name'
            )
            ->orderByRaw('INET_ATON(p.public_ip) ASC, p.protocol ASC, p.public_port_from ASC')
            ->get();

        return view('public_port_forwards.index', [
            'rows'    => $rows,
            'canEdit' => $this->can('edit_all'),
        ]);
    }

    public function create()
    {
        abort_unless($this->can('edit_all'), 403);

        $publicIps = PublicIp::allowedIps();

        return view('public_port_forwards.form', [
            'action'    => 'create',
            'record'    => null,
            'publicIps' => $publicIps,
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($this->can('edit_all'), 403);

        $data = $this->validateForward($request);

        $this->checkPortCollision($data);

        $memberId = Auth::user()?->member_id;

        PublicPortForward::create([
            'public_ip'        => $data['public_ip'],
            'public_port_from' => $data['public_port_from'],
            'public_port_to'   => $data['public_port_to'],
            'private_ip'       => $data['private_ip'],
            'private_port_from'=> $data['private_port_from'],
            'private_port_to'  => $data['private_port_to'],
            'protocol'         => $data['protocol'],
            'enabled'          => $data['enabled'],
            'created'          => now(),
            'created_by'       => $memberId,
        ]);

        session()->flash('success', 'Port forward byl uložen.');
        return redirect()->route('public-port-forwards.index');
    }

    public function edit(int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $record    = PublicPortForward::findOrFail($id);
        $publicIps = PublicIp::allowedIps();

        return view('public_port_forwards.form', [
            'action'    => 'edit',
            'record'    => $record,
            'publicIps' => $publicIps,
        ]);
    }

    public function update(Request $request, int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $record = PublicPortForward::findOrFail($id);
        $data   = $this->validateForward($request);

        $this->checkPortCollision($data, $id);

        $memberId = Auth::user()?->member_id;

        $record->update([
            'public_ip'        => $data['public_ip'],
            'public_port_from' => $data['public_port_from'],
            'public_port_to'   => $data['public_port_to'],
            'private_ip'       => $data['private_ip'],
            'private_port_from'=> $data['private_port_from'],
            'private_port_to'  => $data['private_port_to'],
            'protocol'         => $data['protocol'],
            'enabled'          => $data['enabled'],
            'modified'         => now(),
            'modified_by'      => $memberId,
        ]);

        session()->flash('success', 'Port forward byl uložen.');
        return redirect()->route('public-port-forwards.index');
    }

    public function destroy(int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        PublicPortForward::findOrFail($id)->delete();

        session()->flash('success', 'Port forward byl smazán.');
        return redirect()->route('public-port-forwards.index');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function validateForward(Request $request): array
    {
        $allowedIps = PublicIp::allowedIps();

        $publicIp  = trim((string) $request->input('public_ip', ''));
        $privateIp = trim((string) $request->input('private_ip', ''));

        $pubFrom  = (int) $request->input('public_port_from', 0);
        $pubTo    = (int) $request->input('public_port_to', 0);
        $lanFrom  = (int) $request->input('private_port_from', 0);
        $lanTo    = (int) $request->input('private_port_to', 0);

        if ($pubTo  <= 0) $pubTo  = $pubFrom;
        if ($lanTo  <= 0) $lanTo  = $lanFrom;

        $proto   = strtolower(trim((string) $request->input('protocol', 'tcp')));
        if (!in_array($proto, ['tcp', 'udp'])) $proto = 'tcp';

        $enabled = $request->boolean('enabled') ? 1 : 0;

        $errors = [];

        if (!in_array($publicIp, $allowedIps, true) || !filter_var($publicIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $errors['public_ip'] = 'Veřejná IP musí být vybrána ze seznamu povolených adres.';
        }
        if (!filter_var($privateIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $errors['private_ip'] = 'Neplatná privátní IPv4 adresa.';
        }

        foreach (['public_port_from' => $pubFrom, 'public_port_to' => $pubTo,
                  'private_port_from' => $lanFrom, 'private_port_to' => $lanTo] as $field => $val) {
            if ($val < 1 || $val > 65535) {
                $errors[$field] = 'Port musí být v rozsahu 1–65535.';
            }
        }

        if (!isset($errors['public_port_from']) && !isset($errors['public_port_to']) && $pubFrom > $pubTo) {
            $errors['public_port_from'] = 'Neplatný veřejný rozsah portů.';
        }
        if (!isset($errors['private_port_from']) && !isset($errors['private_port_to']) && $lanFrom > $lanTo) {
            $errors['private_port_from'] = 'Neplatný privátní rozsah portů.';
        }
        if (empty($errors) && ($pubTo - $pubFrom) !== ($lanTo - $lanFrom)) {
            $errors['private_port_from'] = 'Veřejný a privátní rozsah portů musí mít stejnou velikost.';
        }

        if ($errors) {
            back()->withInput()->withErrors($errors)->throwResponse();
        }

        return [
            'public_ip'         => $publicIp,
            'public_port_from'  => $pubFrom,
            'public_port_to'    => $pubTo,
            'private_ip'        => $privateIp,
            'private_port_from' => $lanFrom,
            'private_port_to'   => $lanTo,
            'protocol'          => $proto,
            'enabled'           => $enabled,
        ];
    }

    private function checkPortCollision(array $data, int $ignoreId = 0): void
    {
        $exists = DB::table('public_port_forwards')
            ->where('public_ip', $data['public_ip'])
            ->where('protocol', $data['protocol'])
            ->where('enabled', 1)
            ->where('id', '!=', $ignoreId)
            ->where('public_port_from', '<=', $data['public_port_to'])
            ->where('public_port_to', '>=', $data['public_port_from'])
            ->exists();

        if ($exists) {
            back()->withInput()->withErrors(['public_port_from' => 'Kolize portů — tento rozsah je již obsazen.'])->throwResponse();
        }
    }
}
