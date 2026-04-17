<?php

namespace App\Http\Controllers;

use App\Models\SavedFilter;
use Illuminate\Http\Request;

class SavedFilterController extends Controller
{
    public function index(Request $request)
    {
        $page = $request->query('page', '');
        $filters = SavedFilter::where('user_id', auth()->id())
            ->where('page', $page)
            ->orderBy('name')
            ->get(['id', 'name', 'filters'])
            ->map(fn($f) => ['id' => $f->id, 'name' => $f->name, 'filters' => $f->filters]);

        return response()->json($filters);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'    => 'required|string|max:100',
            'page'    => 'required|string|max:50',
            'filters' => 'required|array',
        ]);

        $existing = SavedFilter::where('user_id', auth()->id())
            ->where('page', $validated['page'])
            ->where('name', $validated['name'])
            ->first();

        if ($existing) {
            $existing->update(['filters' => $validated['filters']]);
            $saved = $existing;
        } else {
            $saved = SavedFilter::create([
                'user_id' => auth()->id(),
                'name'    => $validated['name'],
                'page'    => $validated['page'],
                'filters' => $validated['filters'],
            ]);
        }

        return response()->json(['id' => $saved->id, 'name' => $saved->name]);
    }

    public function destroy(int $id)
    {
        $filter = SavedFilter::where('id', $id)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $filter->delete();

        return response()->json(['ok' => true]);
    }
}
