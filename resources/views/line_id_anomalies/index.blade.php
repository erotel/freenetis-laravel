@extends('layouts.app')
@section('title', 'Anomálie line-id')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">Anomálie line-id (IPoE)</div>
@endsection
@section('content')
@php
    $sevMap  = ['critical' => 'm-tag-red', 'high' => 'm-tag-amber', 'warning' => 'm-tag-gray'];
    $sevName = ['critical' => 'Kritická', 'high' => 'Vysoká', 'warning' => 'Varování'];
    $typeName = [
        'identity_cross' => 'Křížení identit (přehození portů)',
        'mac_moved'      => 'Přesun / klon MAC',
        'unknown_device' => 'Neznámé zařízení na portu',
    ];
    $memberLabel = function ($iface) {
        $m = $iface?->device?->user?->member;
        return $m ? ('ID ' . $m->id . ' – ' . $m->name) : null;
    };
@endphp
<div class="m-page">
<div class="m-title-row"><h2>Anomálie line-id</h2></div>
<div class="m-subtitle">
    Otevřených: {{ $items->total() }}
    @if($openCritical > 0) · <span style="color:#c0392b;font-weight:600">{{ $openCritical }} kritických</span> @endif
</div>

<div class="m-card" style="margin-bottom:16px;padding:14px 1.25rem">
    <form method="GET" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">
        <div>
            <div class="m-form-label">Severita</div>
            <select class="m-form-select" style="width:150px" name="severity" onchange="this.form.submit()">
                <option value="" @selected($severity === '')>— vše —</option>
                <option value="critical" @selected($severity === 'critical')>Kritická</option>
                <option value="high"     @selected($severity === 'high')>Vysoká</option>
                <option value="warning"  @selected($severity === 'warning')>Varování</option>
            </select>
        </div>
        <label style="display:flex;align-items:center;gap:6px;padding-bottom:6px;cursor:pointer">
            <input type="checkbox" name="resolved" value="1" @checked($showResolved) onchange="this.form.submit()">
            Zobrazit i vyřešené
        </label>
    </form>
</div>

<div style="margin-bottom:8px">{{ $items->links() }}</div>

<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:90px">Severita</th>
            <th>Typ</th>
            <th>Port (circuit-id)</th>
            <th>Očekáván (line-id)</th>
            <th>Viděná MAC</th>
            <th>Patří komu</th>
            <th style="width:60px">Počet</th>
            <th style="width:130px">Naposledy</th>
            @if($canResolve)<th style="width:90px"></th>@endif
        </tr>
    </thead>
    <tbody>
        @forelse($items as $a)
        <tr @if($a->resolved_at) style="opacity:.5" @endif>
            <td><span class="m-tag {{ $sevMap[$a->severity] ?? 'm-tag-gray' }}">{{ $sevName[$a->severity] ?? $a->severity }}</span></td>
            <td>{{ $typeName[$a->type] ?? $a->type }}</td>
            <td style="font-family:monospace;font-size:13px">{{ $a->circuit_id }}</td>
            <td>{{ $memberLabel($a->expectedIface) ?? '—' }}</td>
            <td style="font-family:monospace;font-size:13px">{{ $a->seen_mac }}</td>
            <td>{{ $memberLabel($a->seenIface) ?? ($a->seen_iface_id ? ('iface '.$a->seen_iface_id) : 'neregistrovaná') }}</td>
            <td>{{ $a->seen_count }}</td>
            <td style="font-size:13px">{{ optional($a->last_seen)->format('d.m.Y H:i') }}</td>
            @if($canResolve)
            <td>
                @if(!$a->resolved_at)
                <form method="POST" action="{{ route('line_id_anomalies.resolve', $a->id) }}"
                      onsubmit="return confirm('Označit anomálii jako vyřešenou?')">
                    @csrf
                    <button class="m-btn" type="submit" style="padding:2px 8px;font-size:13px">Vyřešit</button>
                </form>
                @else <span class="m-tag m-tag-green" style="font-size:12px">Vyřešeno</span>
                @endif
            </td>
            @endif
        </tr>
        @empty
        <tr><td colspan="{{ $canResolve ? 9 : 8 }}" style="text-align:center;color:#aaa;padding:2rem">Žádné anomálie. 🎉</td></tr>
        @endforelse
    </tbody>
</table>
</div>

<div style="margin-top:12px">{{ $items->links() }}</div>
</div>
@endsection
