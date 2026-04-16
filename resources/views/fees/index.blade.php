@extends('layouts.app')
@section('title', 'Tarify')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs"><a href="{{ route('fees.index') }}">Tarify</a></div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Tarify</h2></div>
<div class="m-subtitle">Celkem: {{ $fees->total() }} záznamů</div>

@if($canNew)
<div class="m-actions">
    <a class="m-btn m-btn-success" href="{{ route('fees.create') }}">+ Přidat tarif</a>
</div>
@endif

@php
    $nextDir = fn(string $col) => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    $arrow   = fn(string $col) => $sort === $col ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
    $sortUrl = fn(string $col) => request()->fullUrlWithQuery(['sort' => $col, 'dir' => $nextDir($col), 'page' => 1]);
@endphp

<div style="margin-bottom:8px">{{ $fees->links() }}</div>

<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px"><a class="m-link-sm" href="{{ $sortUrl('id') }}">ID{{ $arrow('id') }}</a></th>
            <th style="width:130px"><a class="m-link-sm" href="{{ $sortUrl('type_id') }}">Typ{{ $arrow('type_id') }}</a></th>
            <th><a class="m-link-sm" href="{{ $sortUrl('name') }}">Název{{ $arrow('name') }}</a></th>
            <th style="width:110px;text-align:right"><a class="m-link-sm" href="{{ $sortUrl('fee') }}">Poplatek{{ $arrow('fee') }}</a></th>
            <th style="width:100px"><a class="m-link-sm" href="{{ $sortUrl('from') }}">Datum od{{ $arrow('from') }}</a></th>
            <th style="width:100px"><a class="m-link-sm" href="{{ $sortUrl('to') }}">Datum do{{ $arrow('to') }}</a></th>
            <th style="width:80px">Akce</th>
        </tr>
    </thead>
    <tbody>
        @forelse($fees as $fee)
        <tr>
            <td>{{ $fee->id }}</td>
            <td>{{ \App\Models\Fee::typeLabels()[$fee->type_id] ?? $fee->enumType?->value ?? $fee->type_id }}</td>
            <td>{{ $fee->name ?: '—' }}</td>
            <td style="text-align:right;font-family:monospace;font-size:12px">{{ number_format($fee->fee, 2, ',', ' ') }} Kč</td>
            <td style="font-size:12px">{{ $fee->from?->format('d.m.Y') }}</td>
            <td style="font-size:12px">{{ $fee->to && $fee->to->year < 9999 ? $fee->to->format('d.m.Y') : '∞' }}</td>
            <td>
                <div style="display:flex;gap:6px">
                    @if(!$fee->readonly && $canEdit)
                    <a class="m-link-sm" href="{{ route('fees.edit', $fee->id) }}">Upravit</a>
                    @endif
                    @if(!$fee->readonly && $canDelete)
                    <form method="POST" action="{{ route('fees.destroy', $fee->id) }}" style="display:inline"
                          onsubmit="return confirm('Opravdu smazat tarif?')">
                        @csrf @method('DELETE')
                        <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;font-size:12px;color:#c0392b">Smazat</button>
                    </form>
                    @endif
                </div>
            </td>
        </tr>
        @empty
        <tr><td colspan="7" style="text-align:center;color:#aaa;padding:2rem">Žádné tarify.</td></tr>
        @endforelse
    </tbody>
</table>
</div>

<div style="margin-top:12px">{{ $fees->links() }}</div>
</div>
@endsection
