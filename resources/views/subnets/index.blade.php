@extends('layouts.app')
@section('title', 'Subnety')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs"><a href="{{ route('subnets.index') }}">Subnety</a></div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Seznam subnetů</h2></div>
<div class="m-subtitle">Celkem: {{ $subnets->total() }} záznamů</div>

<div class="m-card" style="margin-bottom:16px;padding:14px 1.25rem">
    <form method="GET" action="{{ route('subnets.index') }}" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
        <div>
            <div class="m-form-label">Hledat</div>
            <input class="m-form-input" style="width:220px" type="text" name="search"
                   value="{{ $search }}" placeholder="Název nebo IP adresa...">
        </div>
        <div>
            <div class="m-form-label">Na stránku</div>
            <select class="m-form-select" style="width:80px" name="record_per_page" onchange="this.form.submit()">
                @foreach([50,100,150,200,250,300,350,400,450,500] as $n)
                <option value="{{ $n }}" @selected($perPage === $n)>{{ $n }}</option>
                @endforeach
            </select>
        </div>
        <div style="display:flex;gap:6px;padding-bottom:1px">
            <button class="m-btn m-btn-primary" type="submit">Hledat</button>
            @if($search) <a class="m-btn" href="{{ route('subnets.index') }}">Zrušit filtr</a> @endif
        </div>
        @if(request('sort'))<input type="hidden" name="sort" value="{{ $sort }}">@endif
        @if(request('dir'))<input type="hidden" name="dir" value="{{ $dir }}">@endif
    </form>
</div>

@if($canNew)
<div class="m-actions">
    <a class="m-btn m-btn-success" href="{{ route('subnets.create') }}">+ Přidat subnet</a>
</div>
@endif

@php
    $nextDir = fn(string $col) => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    $arrow   = fn(string $col) => $sort === $col ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
    $sortUrl = fn(string $col) => request()->fullUrlWithQuery(['sort' => $col, 'dir' => $nextDir($col), 'page' => 1]);
    $cols = 4 + ($showDhcp ? 1 : 0) + ($showDns ? 1 : 0) + ($showQos ? 1 : 0) + 1;
@endphp

<div style="margin-bottom:8px">{{ $subnets->links() }}</div>

<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px"><a class="m-link-sm" href="{{ $sortUrl('id') }}">ID{{ $arrow('id') }}</a></th>
            <th><a class="m-link-sm" href="{{ $sortUrl('name') }}">Název{{ $arrow('name') }}</a></th>
            <th><a class="m-link-sm" href="{{ $sortUrl('network_address') }}">Síť/Maska{{ $arrow('network_address') }}</a></th>
            <th style="width:80px">Počet IP</th>
            @if($showDhcp)<th style="width:60px">DHCP</th>@endif
            @if($showDns) <th style="width:50px">DNS</th> @endif
            @if($showQos) <th style="width:50px">QoS</th> @endif
            <th style="width:100px">Akce</th>
        </tr>
    </thead>
    <tbody>
        @forelse($subnets as $subnet)
        <tr>
            <td>{{ $subnet->id }}</td>
            <td><a class="m-link" href="{{ route('subnets.show', $subnet->id) }}">{{ $subnet->name ?: '—' }}</a></td>
            <td style="font-family:monospace;font-size:12px">{{ $subnet->network_address }}/{{ $subnet->netmask }}</td>
            <td>{{ $subnet->ip_addresses_count }}</td>
            @if($showDhcp)<td>@if($subnet->dhcp)<span class="m-tag m-tag-green">Ano</span>@else —@endif</td>@endif
            @if($showDns) <td>@if($subnet->dns) <span class="m-tag m-tag-green">Ano</span>@else —@endif</td>@endif
            @if($showQos) <td>@if($subnet->qos) <span class="m-tag m-tag-blue">Ano</span>@else  —@endif</td>@endif
            <td>
                <div style="display:flex;gap:6px">
                    <a class="m-link-sm" href="{{ route('subnets.show', $subnet->id) }}">Detail</a>
                    @if($canEdit)
                    <a class="m-link-sm" href="{{ route('subnets.edit', $subnet->id) }}">Upravit</a>
                    @endif
                    @if($canDelete)
                    <form method="POST" action="{{ route('subnets.destroy', $subnet->id) }}" style="display:inline"
                          onsubmit="return confirm('Opravdu smazat subnet {{ addslashes($subnet->network_address) }}?')">
                        @csrf @method('DELETE')
                        <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;font-size:12px;color:#c0392b">Smazat</button>
                    </form>
                    @endif
                </div>
            </td>
        </tr>
        @empty
        <tr><td colspan="{{ $cols }}" style="text-align:center;color:#aaa;padding:2rem">Žádné subnety nebyly nalezeny.</td></tr>
        @endforelse
    </tbody>
</table>
</div>

<div style="margin-top:12px">{{ $subnets->links() }}</div>
</div>
@endsection
