@extends('layouts.app')
@section('title', 'Audit změn')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">Audit změn</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Audit změn (kdo / co / kdy)</h2></div>

<div class="m-card" style="margin-bottom:16px">
    <form method="GET" action="{{ route('audit.index') }}"
          style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">
        <div style="flex:2 1 240px">
            <label style="display:block;font-size:13px;color:#666">Hledat v hodnotách (např. IP, jméno)</label>
            <input type="text" name="q" value="{{ $q }}" placeholder="10.20.30.40"
                   style="width:100%;padding:6px 8px;border:1px solid #ccc;border-radius:4px">
        </div>
        <div style="flex:1 1 160px">
            <label style="display:block;font-size:13px;color:#666">Typ objektu</label>
            <select name="type" style="width:100%;padding:6px 8px;border:1px solid #ccc;border-radius:4px">
                <option value="">— vše —</option>
                @foreach($types as $t)
                    <option value="{{ $t }}" @selected($type === $t)>{{ $t }}</option>
                @endforeach
            </select>
        </div>
        <div style="flex:1 1 120px">
            <label style="display:block;font-size:13px;color:#666">Akce</label>
            <input type="text" name="action" value="{{ $action }}" placeholder="created…"
                   style="width:100%;padding:6px 8px;border:1px solid #ccc;border-radius:4px">
        </div>
        <div style="flex:1 1 120px">
            <label style="display:block;font-size:13px;color:#666">Uživatel (login/ID)</label>
            <input type="text" name="user" value="{{ $user }}"
                   style="width:100%;padding:6px 8px;border:1px solid #ccc;border-radius:4px">
        </div>
        <div style="flex:1 1 130px">
            <label style="display:block;font-size:13px;color:#666">Od</label>
            <input type="date" name="from" value="{{ $from }}"
                   style="width:100%;padding:6px 8px;border:1px solid #ccc;border-radius:4px">
        </div>
        <div style="flex:1 1 130px">
            <label style="display:block;font-size:13px;color:#666">Do</label>
            <input type="date" name="to" value="{{ $to }}"
                   style="width:100%;padding:6px 8px;border:1px solid #ccc;border-radius:4px">
        </div>
        <div style="display:flex;gap:6px">
            <button type="submit" class="m-btn m-btn-success">Filtrovat</button>
            <a class="m-btn" href="{{ route('audit.index') }}">Zrušit</a>
        </div>
    </form>
</div>

<div style="font-size:14px;color:#666;margin-bottom:8px">
    Nalezeno <strong>{{ $logs->total() }}</strong> záznamů.
</div>

@include('audit._history', ['entries' => collect($logs->items()), 'showHeading' => false])

@if($logs->hasPages())
<div style="display:flex;gap:10px;align-items:center;margin-top:12px">
    @if($logs->previousPageUrl())
        <a class="m-btn" href="{{ $logs->previousPageUrl() }}">← Novější</a>
    @endif
    <span style="font-size:14px;color:#666">Strana {{ $logs->currentPage() }} / {{ $logs->lastPage() }}</span>
    @if($logs->nextPageUrl())
        <a class="m-btn" href="{{ $logs->nextPageUrl() }}">Starší →</a>
    @endif
</div>
@endif

</div>
@endsection
