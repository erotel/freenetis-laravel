@extends('layouts.app')
@section('title', 'Zařízení: ' . $user->full_name)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('users.index') }}">Uživatelé</a> &raquo;
    <a href="{{ route('users.show', $user->id) }}">{{ $user->full_name }}</a> &raquo;
    Zařízení
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Zařízení uživatele {{ $user->full_name }}</h2></div>

@if($canNew)
<div class="m-actions">
    <a class="m-btn m-btn-success" href="{{ route('devices.add', $user->id) }}">+ Přidat zařízení</a>
</div>
@endif

@if($devices->isEmpty())
<div class="m-card">
    <div style="text-align:center;color:#aaa;padding:2rem">Žádná zařízení.</div>
</div>
@else
@php
    $nextDir = fn(string $col) => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    $arrow   = fn(string $col) => $sort === $col ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
    $sortUrl = fn(string $col) => request()->fullUrlWithQuery(['sort' => $col, 'dir' => $nextDir($col)]);
@endphp
<style>
    table.m-resizable { table-layout: fixed; width: 100%; }
    table.m-resizable th { position: relative; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    table.m-resizable td.m-truncate { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    table.m-resizable .m-col-resizer {
        position: absolute; top: 0; right: 0; width: 6px; height: 100%;
        cursor: col-resize; user-select: none; touch-action: none;
    }
    table.m-resizable .m-col-resizer:hover,
    table.m-resizable .m-col-resizer.m-dragging { background: rgba(0,123,255,0.35); }
    body.m-col-resizing, body.m-col-resizing * { cursor: col-resize !important; user-select: none !important; }
</style>
<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table m-resizable" data-resize-key="user-devices" style="margin-bottom:0">
    <colgroup>
        <col style="width:60px"><col style="width:200px"><col style="width:140px">
        <col style="width:160px"><col style="width:140px"><col style="width:160px"><col style="width:160px">
    </colgroup>
    <thead>
        <tr>
            <th><a class="m-link-sm" href="{{ $sortUrl('id') }}">ID{{ $arrow('id') }}</a></th>
            <th><a class="m-link-sm" href="{{ $sortUrl('name') }}">Název{{ $arrow('name') }}</a></th>
            <th>Typ</th>
            <th>MAC adresa</th>
            <th>IP adresa</th>
            <th>Podsíť</th>
            <th>Akce</th>
        </tr>
    </thead>
    <tbody>
        @foreach($devices as $device)
        @php
            $firstMac = $device->ifaces->pluck('mac')->filter(fn($m) => $m !== null && $m !== '')->first();
            $firstIp  = $device->ifaces->flatMap(fn($i) => $i->ipAddresses)->first();
            $subnet   = $firstIp?->subnet;
            $subnetLabel = $subnet ? ($subnet->name ?? $subnet->network_address) : '—';
        @endphp
        <tr>
            <td class="m-truncate">{{ $device->id }}</td>
            <td class="m-truncate">
                <a class="m-link" href="{{ route('devices.show', $device->id) }}">{{ $device->name }}</a>
            </td>
            <td class="m-truncate">{{ $device->enumType?->value ?? '—' }}</td>
            <td class="m-truncate" style="font-family:monospace;font-size:14px">{{ $firstMac ?? '—' }}</td>
            <td class="m-truncate" style="font-family:monospace;font-size:14px">{{ $firstIp?->ip_address ?? '—' }}</td>
            <td class="m-truncate">{{ $subnetLabel }}</td>
            <td class="m-truncate">
                <div style="display:flex;gap:6px">
                    <a class="m-link-sm" href="{{ route('devices.show', $device->id) }}">Detail</a>
                    @if($canEdit)
                    <a class="m-link-sm" href="{{ route('devices.edit', $device->id) }}">Upravit</a>
                    @endif
                    @if($canDelete)
                    <form method="POST" action="{{ route('devices.destroy', $device->id) }}" style="display:inline"
                          onsubmit="return confirm('Opravdu smazat zařízení {{ addslashes($device->name) }}?')">
                        @csrf @method('DELETE')
                        <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;font-size:14px;color:#c0392b">Smazat</button>
                    </form>
                    @endif
                </div>
            </td>
        </tr>
        @endforeach
    </tbody>
</table>
</div>
<script>
(function () {
    document.querySelectorAll('table.m-resizable').forEach(function (table) {
        var key = 'm-col-widths:' + (table.dataset.resizeKey || 'default');
        var cols = table.querySelectorAll('colgroup > col');
        var ths  = table.querySelectorAll('thead > tr > th');
        if (!cols.length || cols.length !== ths.length) return;

        try {
            var saved = JSON.parse(localStorage.getItem(key) || 'null');
            if (Array.isArray(saved) && saved.length === cols.length) {
                saved.forEach(function (w, i) { if (w) cols[i].style.width = w + 'px'; });
            }
        } catch (e) {}

        function persist() {
            var widths = Array.from(cols).map(function (c) { return c.offsetWidth; });
            try { localStorage.setItem(key, JSON.stringify(widths)); } catch (e) {}
        }

        ths.forEach(function (th, idx) {
            if (idx === ths.length - 1) return;
            var grip = document.createElement('span');
            grip.className = 'm-col-resizer';
            grip.addEventListener('mousedown', function (ev) {
                ev.preventDefault();
                var startX = ev.clientX;
                var startW = cols[idx].offsetWidth;
                grip.classList.add('m-dragging');
                document.body.classList.add('m-col-resizing');
                function onMove(e) {
                    var w = Math.max(30, startW + (e.clientX - startX));
                    cols[idx].style.width = w + 'px';
                }
                function onUp() {
                    document.removeEventListener('mousemove', onMove);
                    document.removeEventListener('mouseup', onUp);
                    grip.classList.remove('m-dragging');
                    document.body.classList.remove('m-col-resizing');
                    persist();
                }
                document.addEventListener('mousemove', onMove);
                document.addEventListener('mouseup', onUp);
            });
            grip.addEventListener('dblclick', function () {
                cols[idx].style.width = '';
                persist();
            });
            th.appendChild(grip);
        });
    });
})();
</script>
@endif
</div>
@endsection
