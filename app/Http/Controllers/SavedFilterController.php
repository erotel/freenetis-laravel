<?php

namespace App\Http\Controllers;

use App\Domains\Members\Repositories\SavedFilterRepository;
use Illuminate\Http\Request;

/** Uložené filtry seznamů (jen vlastní); data v doméně Members. */
class SavedFilterController extends Controller
{
    public function __construct(private SavedFilterRepository $filters) {}

    public function index(Request $request)
    {
        return response()->json($this->filters->forPage((int) auth()->id(), (string) $request->query('page', '')));
    }

    public function store(Request $request)
    {
        $v = $request->validate([
            'name'    => 'required|string|max:100',
            'page'    => 'required|string|max:50',
            'filters' => 'required|array',
        ]);
        $saved = $this->filters->save((int) auth()->id(), $v['page'], $v['name'], $v['filters']);

        return response()->json(['id' => $saved->id, 'name' => $saved->name]);
    }

    public function destroy(int $id)
    {
        $this->filters->deleteOwnOrFail((int) auth()->id(), $id);
        return response()->json(['ok' => true]);
    }
}
