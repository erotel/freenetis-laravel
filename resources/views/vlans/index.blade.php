@extends('layouts.app')
@section('title', 'VLANy')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs"><a href="{{ route('vlans.index') }}">VLANy</a></div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Seznam VLANů</h2></div>
<div class="m-subtitle">Celkem: {{ $vlans->total() }} záznamů</div>

<form method="GET" action="{{ route('vlans.index') }}" style="margin:8px 0 16px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <input type="text" name="q" value="{{ $q ?? '' }}"
           placeholder="Hledat: název, komentář, 802.1Q tag, ID"
           style="flex:1;min-width:260px;padding:6px 10px;border:1px solid #ccc;border-radius:4px">
    <button class="m-btn" type="submit">Hledat</button>
    @if(($q ?? '') !== '')
    <a class="m-link" href="{{ route('vlans.index') }}">Zrušit filtr</a>
    @endif
</form>

@if($canNew)
<div class="m-actions">
    <a class="m-btn m-btn-success" href="{{ route('vlans.create') }}">+ Přidat VLAN</a>
</div>
@endif

@php
    $nextDir = fn(string $col) => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    $arrow   = fn(string $col) => $sort === $col ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
    $sortUrl = fn(string $col) => request()->fullUrlWithQuery(['sort' => $col, 'dir' => $nextDir($col), 'page' => 1]);
@endphp

<div style="margin-bottom:8px">{{ $vlans->links() }}</div>

<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px"><a class="m-link-sm" href="{{ $sortUrl('id') }}">ID{{ $arrow('id') }}</a></th>
            <th><a class="m-link-sm" href="{{ $sortUrl('name') }}">Název{{ $arrow('name') }}</a></th>
            <th style="width:100px"><a class="m-link-sm" href="{{ $sortUrl('tag_802_1q') }}">802.1Q tag{{ $arrow('tag_802_1q') }}</a></th>
            <th>Komentář</th>
            <th style="width:100px">Počet rozhraní</th>
            <th style="width:100px">Akce</th>
        </tr>
    </thead>
    <tbody>
        @forelse($vlans as $vlan)
        <tr>
            <td>{{ $vlan->id }}</td>
            <td><a class="m-link" href="{{ route('vlans.show', $vlan->id) }}">{{ $vlan->name ?: '—' }}</a></td>
            <td>{{ $vlan->tag_802_1q }}</td>
            <td>{{ $vlan->comment ?: '—' }}</td>
            <td>{{ $vlan->ifaces_count }}</td>
            <td>
                <div style="display:flex;gap:6px">
                    <a class="m-link-sm" href="{{ route('vlans.show', $vlan->id) }}">Detail</a>
                    @if($canEdit)
                    <a class="m-link-sm" href="{{ route('vlans.edit', $vlan->id) }}">Upravit</a>
                    @endif
                    @if($canDelete && $vlan->tag_802_1q !== \App\Models\Vlan::DEFAULT_VLAN_TAG)
                    <form method="POST" action="{{ route('vlans.destroy', $vlan->id) }}" style="display:inline"
                          onsubmit="return confirm('Opravdu smazat VLAN {{ addslashes((string)$vlan) }}?')">
                        @csrf @method('DELETE')
                        <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;font-size:14px;color:#c0392b">Smazat</button>
                    </form>
                    @endif
                </div>
            </td>
        </tr>
        @empty
        <tr><td colspan="6" style="text-align:center;color:#aaa;padding:2rem">Žádné VLANy nebyly nalezeny.</td></tr>
        @endforelse
    </tbody>
</table>
</div>

<div style="margin-top:4px;display:flex;align-items:center;gap:10px;justify-content:space-between">
    <div style="margin-top:8px">{{ $vlans->links() }}</div>
    <form method="GET" action="{{ route('vlans.index') }}" style="display:flex;align-items:center;gap:6px;font-size:16px;margin-top:8px">
        @if(request('sort'))<input type="hidden" name="sort" value="{{ $sort }}">@endif
        @if(request('dir'))<input type="hidden" name="dir" value="{{ $dir }}">@endif
        <span>Na stránku:</span>
        <select class="m-form-select" style="width:70px" name="record_per_page" onchange="this.form.submit()">
            @foreach([50,100,150,200,250,300,350,400,450,500] as $n)
            <option value="{{ $n }}" @selected($perPage === $n)>{{ $n }}</option>
            @endforeach
        </select>
    </form>
</div>
</div>
@endsection
