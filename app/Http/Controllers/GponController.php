<?php

namespace App\Http\Controllers;

use App\Models\Ont;
use App\Services\GponService;
use Illuminate\Http\Request;

class GponController extends Controller
{
    public function __construct(private GponService $gponService) {}

    public function index(Request $request)
    {
        $status = $request->input('status');

        $query = Ont::with('member')->orderByDesc('created_at');

        if ($status && in_array($status, ['new', 'registered', 'removed'])) {
            $query->where('reg_status', $status);
        }

        $onts = $query->paginate(50)->withQueryString();

        $counts = [
            'new'        => Ont::newOnts()->count(),
            'registered' => Ont::registered()->count(),
            'removed'    => Ont::removed()->count(),
        ];

        return view('gpon.index', compact('onts', 'counts', 'status'));
    }

    public function show(int $id)
    {
        $ont     = Ont::with(['member', 'device'])->findOrFail($id);
        $details = null;

        if ($ont->reg_status === 'registered') {
            try {
                $details = $this->gponService->getOntDetails($ont);
            } catch (\Exception $e) {
                $details = null;
            }
        }

        return view('gpon.show', compact('ont', 'details'));
    }

    public function scan(Request $request)
    {
        try {
            $count = $this->gponService->scanNewOnts();
            return back()->with('success', "Skenování dokončeno. Nalezeno {$count} nových ONT.");
        } catch (\Exception $e) {
            return back()->with('error', 'Chyba při skenování: ' . $e->getMessage());
        }
    }

    public function register(Request $request, int $id)
    {
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
                Ont::findOrFail($id)->update(['member_id' => $memberId, 'user_name' => null]);
            }

            return redirect()->route('gpon.show', $id)->with('success', 'ONT byla úspěšně zaregistrována.');
        } catch (\Exception $e) {
            return redirect()->route('gpon.show', $id)->with('error', 'Chyba při registraci: ' . $e->getMessage());
        }
    }

    public function remove(int $id)
    {
        try {
            $this->gponService->removeOntById($id);
            return redirect()->route('gpon.index')->with('success', 'ONT byla odebrána.');
        } catch (\Exception $e) {
            return redirect()->route('gpon.show', $id)->with('error', 'Chyba při odebrání: ' . $e->getMessage());
        }
    }

    public function map()
    {
        $onts = \App\Models\Ont::whereNotNull('gps_lat')
            ->whereNotNull('gps_lng')
            ->where('reg_status', 'registered')
            ->get(['id', 'serial', 'house_no', 'user_name', 'gps_lat', 'gps_lng', 'gpon_port', 'port_index', 'ont_id', 'olt_ip']);

        $onlineStatus = $this->gponService->getBatchOnlineStatus($onts);

        return view('gpon.map', compact('onts', 'onlineStatus'));
    }
}
