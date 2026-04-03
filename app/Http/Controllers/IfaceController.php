<?php

namespace App\Http\Controllers;

use App\Models\Iface;
use App\Services\AclService;
use Illuminate\Http\Request;

class IfaceController extends Controller
{
    public function __construct(private AclService $acl) {}

    private function can(string $action = 'view_all'): bool
    {
        return $this->acl->hasAccess(auth()->id(), $action, 'Ifaces_Controller', 'iface');
    }

    public function index(Request $request)
    {
        abort_unless($this->can(), 403);

        $sort = in_array($request->sort, ['id', 'name', 'type', 'device_id']) ? $request->sort : 'id';
        $dir  = $request->dir === 'desc' ? 'desc' : 'asc';
        $perPage = (int) ($request->record_per_page ?? 50);
        if (!in_array($perPage, [50, 100, 150, 200, 250, 300])) {
            $perPage = 50;
        }

        $search = trim((string) ($request->search ?? ''));

        $query = Iface::with('device.user.member')->orderBy($sort, $dir);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('mac', 'like', "%{$search}%");
            });
        }

        $ifaces = $query->paginate($perPage)->withQueryString();

        return view('ifaces.index', compact('ifaces', 'sort', 'dir', 'perPage', 'search'));
    }

    public function show(int $id)
    {
        abort_unless($this->can(), 403);

        $iface = Iface::with([
            'device.user.member',
            'ipAddresses.subnet',
            'vlans',
        ])->findOrFail($id);

        return view('ifaces.show', compact('iface'));
    }
}
