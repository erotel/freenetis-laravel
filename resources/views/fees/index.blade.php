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

<div class="m-actions" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    @if($canNew)
    <a class="m-btn m-btn-success" href="{{ route('fees.create') }}">+ Přidat tarif</a>
    @endif
    @if($showArchived)
    <a class="m-btn" href="{{ route('fees.index') }}">Skrýt archivované</a>
    @else
    <a class="m-btn" href="{{ route('fees.index', ['archived' => 1]) }}">Zobrazit i archivované ({{ $archivedCount }})</a>
    @endif
</div>

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
            <th style="width:90px;text-align:right">Aktivních</th>
            <th style="width:80px">Akce</th>
        </tr>
    </thead>
    <tbody>
        @forelse($fees as $fee)
        <tr @if($fee->archived) style="opacity:.55;background:#fafafa" @endif>
            <td>{{ $fee->id }}</td>
            <td>{{ \App\Models\Fee::typeLabels()[$fee->type_id] ?? $fee->enumType?->value ?? $fee->type_id }}</td>
            <td>
                {{ $fee->name ?: '—' }}
                @if($fee->archived)
                <span style="font-size:11px;color:#888;border:1px solid #ccc;border-radius:3px;padding:1px 5px;margin-left:6px">archivováno</span>
                @endif
            </td>
            <td style="text-align:right;font-family:monospace;font-size:14px">{{ number_format($fee->fee, 2, ',', ' ') }} Kč</td>
            <td style="font-size:14px">{{ $fee->from?->format('d.m.Y') }}</td>
            <td style="font-size:14px">{{ $fee->to && $fee->to->year < 9999 ? $fee->to->format('d.m.Y') : '∞' }}</td>
            <td style="text-align:right;font-family:monospace;font-size:14px">
                @if(($fee->active_count ?? 0) > 0)
                <a class="m-link" href="{{ route('fees.members', $fee->id) }}" title="Zobrazit členy s tímto tarifem">{{ $fee->active_count }}</a>
                @else
                <span style="color:#bbb">0</span>
                @endif
            </td>
            <td>
                <div style="display:flex;gap:6px;flex-wrap:wrap">
                    @if(!$fee->readonly && $canEdit)
                    <a class="m-link-sm" href="{{ route('fees.edit', $fee->id) }}">Upravit</a>
                    @endif
                    @if(!$fee->readonly && $canEdit && ($fee->archived || !($fee->has_active ?? false)))
                    <form method="POST" action="{{ route('fees.toggle_archive', $fee->id) }}" style="display:inline">
                        @csrf
                        <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;font-size:14px;color:#2c7"
                                title="{{ $fee->archived ? 'Vrátit do nabídky' : 'Skrýt z nabídky (historie zůstává)' }}">{{ $fee->archived ? 'Obnovit' : 'Archivovat' }}</button>
                    </form>
                    @endif
                    @if(!$fee->readonly && $canDelete)
                    <form method="POST" action="{{ route('fees.destroy', $fee->id) }}" style="display:inline"
                          onsubmit="return confirm('Opravdu smazat tarif?')">
                        @csrf @method('DELETE')
                        <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;font-size:14px;color:#c0392b">Smazat</button>
                    </form>
                    @endif
                </div>
            </td>
        </tr>
        @empty
        <tr><td colspan="8" style="text-align:center;color:#aaa;padding:2rem">Žádné tarify.</td></tr>
        @endforelse
    </tbody>
</table>
</div>

<div style="margin-top:12px">{{ $fees->links() }}</div>
</div>
@endsection
