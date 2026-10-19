<?php

namespace App\Http\Controllers;

use App\Domains\Network\Repositories\PublicPortForwardRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Port forwardy z veřejných IP; data v doméně Network. */
class PublicPortForwardController extends Controller
{
    public function __construct(private PublicPortForwardRepository $forwards) {}

    private function can(string $action = 'view_all'): bool
    {
        return $this->aclCheck($action, 'Network_Controller', 'public_ports');
    }

    public function index(Request $request)
    {
        abort_unless($this->can(), 403);

        $q = trim((string) $request->query('q', ''));

        return view('public_port_forwards.index', [
            'rows'    => $this->forwards->search($q),
            'q'       => $q,
            'canEdit' => $this->can('edit_all'),
        ]);
    }

    public function create()
    {
        abort_unless($this->can('edit_all'), 403);

        $publicIps = $this->forwards->allowedPublicIps();

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

        $this->forwards->create($data, Auth::user()?->member_id);

        session()->flash('success', 'Port forward byl uložen.');
        return redirect()->route('public-port-forwards.index');
    }

    public function edit(int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $record    = $this->forwards->findOrFail($id);
        $publicIps = $this->forwards->allowedPublicIps();

        return view('public_port_forwards.form', [
            'action'    => 'edit',
            'record'    => $record,
            'publicIps' => $publicIps,
        ]);
    }

    public function update(Request $request, int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $record = $this->forwards->findOrFail($id);
        $data   = $this->validateForward($request);

        $this->checkPortCollision($data, $id);

        $this->forwards->update($record, $data, Auth::user()?->member_id);

        session()->flash('success', 'Port forward byl uložen.');
        return redirect()->route('public-port-forwards.index');
    }

    public function destroy(int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $this->forwards->delete($this->forwards->findOrFail($id));

        session()->flash('success', 'Port forward byl smazán.');
        return redirect()->route('public-port-forwards.index');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function validateForward(Request $request): array
    {
        $allowedIps = $this->forwards->allowedPublicIps();

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
        if ($this->forwards->collides($data, $ignoreId)) {
            back()->withInput()->withErrors(['public_port_from' => 'Kolize portů — tento rozsah je již obsazen.'])->throwResponse();
        }
    }
}
