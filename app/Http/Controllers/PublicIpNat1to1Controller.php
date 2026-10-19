<?php

namespace App\Http\Controllers;

use App\Domains\Network\Repositories\PublicIpNatRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class PublicIpNat1to1Controller extends Controller
{
    public function __construct(private PublicIpNatRepository $nat)
    {
    }

    private function can(string $action = 'view_all'): bool
    {
        return $this->aclCheck($action, 'Network_Controller', 'public_ip_nat');
    }

    public function index(Request $request)
    {
        abort_unless($this->can(), 403);

        $enabled = $request->query('enabled', 'all');
        $q       = trim((string) $request->query('q', ''));

        $rows = $this->nat->search($enabled, $q);

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

        $record = $this->nat->findOrFail($id);

        return view('public_ip_nat1to1.form', [
            'action' => 'edit',
            'record' => $record,
        ]);
    }

    public function update(Request $request, int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $record = $this->nat->findOrFail($id);

        $data = $request->validate([
            'private_ip' => ['nullable', 'ipv4'],
            'enabled'    => ['nullable', 'boolean'],
        ], [
            'private_ip.ipv4' => 'Neplatná privátní IPv4 adresa.',
        ]);

        $privateIp = $data['private_ip'] ?? null;
        $enabled   = $request->boolean('enabled') ? 1 : 0;

        if ($privateIp !== null && $privateIp === $record->public_ip) {
            throw ValidationException::withMessages([
                'private_ip' => 'Veřejná a privátní IP nesmějí být stejné.',
            ]);
        }

        $this->nat->update($record, ['private_ip' => $privateIp, 'enabled' => $enabled], Auth::user()?->member_id);

        session()->flash('success', 'Záznam byl uložen.');
        return redirect()->route('public-ip-nat.index');
    }

    public function clear(int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $this->nat->update($this->nat->findOrFail($id), ['private_ip' => null], Auth::user()?->member_id);

        session()->flash('success', 'Mapování bylo vymazáno.');
        return redirect()->route('public-ip-nat.index');
    }
}
