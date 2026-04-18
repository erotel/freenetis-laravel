<?php

namespace App\Http\Controllers;

use App\Models\Ont;
use App\Services\GponService;
use Illuminate\Http\Request;

class GponController extends Controller
{
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
        $ont = Ont::with(['member', 'device'])->findOrFail($id);
        return view('gpon.show', compact('ont'));
    }

    public function scan(Request $request)
    {
        try {
            $count = app(GponService::class)->scanNewOnts();
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
        ]);

        try {
            app(GponService::class)->registerOntById(
                $id,
                $request->input('house_no', ''),
                $request->input('user_name', '')
            );
            return redirect()->route('gpon.show', $id)->with('success', 'ONT byla úspěšně zaregistrována.');
        } catch (\Exception $e) {
            return redirect()->route('gpon.show', $id)->with('error', 'Chyba při registraci: ' . $e->getMessage());
        }
    }

    public function remove(int $id)
    {
        try {
            app(GponService::class)->removeOntById($id);
            return redirect()->route('gpon.index')->with('success', 'ONT byla odebrána.');
        } catch (\Exception $e) {
            return redirect()->route('gpon.show', $id)->with('error', 'Chyba při odebrání: ' . $e->getMessage());
        }
    }
}
