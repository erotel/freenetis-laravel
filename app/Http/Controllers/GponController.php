<?php

namespace App\Http\Controllers;

use App\Models\Member;
use App\Models\Ont;
use App\Services\GponService;
use Illuminate\Http\Request;

class GponController extends Controller
{
    public function __construct(private GponService $gponService) {}

    public function index(Request $request)
    {
        $status = $request->input('status');

        $query = Ont::with(['member', 'addedBy'])->orderByDesc('created_at');

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
        $ont     = Ont::with(['member', 'device', 'addedBy'])->findOrFail($id);
        $details = null;

        if ($ont->reg_status === 'registered') {
            try {
                $details = $this->gponService->getOntDetails($ont);
            } catch (\Exception $e) {
                $details = null;
            }
        }

        $customers = Member::whereIn('type', [2, 18])
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('gpon.show', compact('ont', 'details', 'customers'));
    }

    public function updateMember(int $id, Request $request)
    {
        $request->validate([
            'member_id' => 'nullable|integer|exists:members,id',
        ]);

        $ont = Ont::findOrFail($id);
        $ont->member_id = $request->input('member_id') ?: null;
        $ont->save();

        return redirect()->route('gpon.show', $id)->with('success', 'Zákazník upraven.');
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

    public function destroy(int $id)
    {
        $ont = Ont::findOrFail($id);

        if ($ont->reg_status !== 'removed') {
            return redirect()->route('gpon.show', $id)->with('error', 'ONT lze smazat pouze ve stavu Odebrána.');
        }

        $ont->delete();

        return redirect()->route('gpon.index')->with('success', 'ONT byla smazána z evidence.');
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
