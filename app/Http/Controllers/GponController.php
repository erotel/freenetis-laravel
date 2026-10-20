<?php

namespace App\Http\Controllers;

use App\Domains\Access\Gpon\GponService;
use App\Domains\Access\Repositories\OntRepository;
use Illuminate\Http\Request;

class GponController extends Controller
{
    private const ACL_SECTION = 'Gpon_Controller';
    private const ACL_VALUE   = 'gpon';

    public function __construct(private GponService $gponService, private OntRepository $onts) {}

    private function can(string $action): bool
    {
        return $this->aclCheck($action, self::ACL_SECTION, self::ACL_VALUE);
    }

    public function index(Request $request)
    {
        abort_unless($this->can('view_all'), 403);
        $status = $request->input('status');

        $onts   = $this->onts->paginate($status, 50);
        $counts = $this->onts->countsByStatus();

        return view('gpon.index', compact('onts', 'counts', 'status'));
    }

    public function show(int $id)
    {
        abort_unless($this->can('view_all'), 403);
        $ont     = $this->onts->findWithRelationsOrFail($id);
        $details = null;

        if ($ont->reg_status === 'registered') {
            try {
                $details = $this->gponService->getOntDetails($ont);
            } catch (\Exception $e) {
                $details = null;
            }
        }

        $customers = $this->onts->customers();

        return view('gpon.show', compact('ont', 'details', 'customers'));
    }

    public function updateMember(int $id, Request $request)
    {
        abort_unless($this->can('edit_all'), 403);
        $request->validate([
            'member_id' => 'nullable|integer|exists:members,id',
        ]);

        $this->onts->setMember($this->onts->findOrFail($id), $request->input('member_id') ? (int) $request->input('member_id') : null);

        return redirect()->route('gpon.show', $id)->with('success', 'Zákazník upraven.');
    }

    public function scan(Request $request)
    {
        abort_unless($this->can('edit_all'), 403);
        try {
            $count = $this->gponService->scanNewOnts();
            return back()->with('success', "Skenování dokončeno. Nalezeno {$count} nových ONT.");
        } catch (\Exception $e) {
            return back()->with('error', 'Chyba při skenování: ' . $e->getMessage());
        }
    }

    public function register(Request $request, int $id)
    {
        abort_unless($this->can('edit_all'), 403);
        $request->validate([
            'house_no'  => 'nullable|string|max:32',
            'user_name' => 'nullable|string|max:128',
            'member_id' => 'nullable|integer|exists:members,id',
        ]);

        $memberId = $request->input('member_id') ? (int) $request->input('member_id') : null;
        $houseNo  = $request->input('house_no', '');
        $userName = $request->input('user_name', '');

        try {
            $this->gponService->registerOntById($id, $houseNo, $userName);

            if ($memberId) {
                $this->onts->assignRegisteredMember($id, $memberId);
            }

            return redirect()->route('gpon.show', $id)->with('success', 'ONT byla úspěšně zaregistrována.');
        } catch (\Exception $e) {
            return redirect()->route('gpon.show', $id)->with('error', 'Chyba při registraci: ' . $e->getMessage());
        }
    }

    public function remove(int $id)
    {
        abort_unless($this->can('edit_all'), 403);
        try {
            $this->gponService->removeOntById($id);
            return redirect()->route('gpon.index')->with('success', 'ONT byla odebrána.');
        } catch (\Exception $e) {
            return redirect()->route('gpon.show', $id)->with('error', 'Chyba při odebrání: ' . $e->getMessage());
        }
    }

    public function destroy(int $id)
    {
        abort_unless($this->can('delete_all'), 403);
        $ont = $this->onts->findOrFail($id);

        if ($ont->reg_status !== 'removed') {
            return redirect()->route('gpon.show', $id)->with('error', 'ONT lze smazat pouze ve stavu Odebrána.');
        }

        $this->onts->delete($ont);

        return redirect()->route('gpon.index')->with('success', 'ONT byla smazána z evidence.');
    }

    public function map()
    {
        abort_unless($this->can('view_all'), 403);
        $onts = $this->onts->mapped();

        $onlineStatus = $this->gponService->getBatchOnlineStatus($onts);

        return view('gpon.map', compact('onts', 'onlineStatus'));
    }
}
