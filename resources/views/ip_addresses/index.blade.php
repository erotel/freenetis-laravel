@extends('layouts.app')
@section('title', 'IP adresy')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs"><a href="{{ route('ip_addresses.index') }}">IP adresy</a></div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Seznam IP adres</h2></div>
<div class="m-subtitle">Celkem: {{ $ipAddresses->total() }} záznamů</div>

<div class="m-card" style="margin-bottom:16px;padding:14px 1.25rem">
    <form method="GET" action="{{ route('ip_addresses.index') }}" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
        <div>
            <div class="m-form-label">Hledat</div>
            <input class="m-form-input" style="width:200px" type="text" name="search"
                   value="{{ $search }}" placeholder="Podle IP adresy...">
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
            @if($search) <a class="m-btn" href="{{ route('ip_addresses.index') }}">Zrušit filtr</a> @endif
        </div>
        @if(request('sort'))<input type="hidden" name="sort" value="{{ $sort }}">@endif
        @if(request('dir'))<input type="hidden" name="dir" value="{{ $dir }}">@endif
    </form>
</div>

@if($canNew)
<div class="m-actions">
    <a class="m-btn m-btn-success" href="{{ route('ip_addresses.create') }}">+ Přidat IP adresu</a>
</div>
@endif

@php
    $nextDir = fn(string $col) => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    $arrow   = fn(string $col) => $sort === $col ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
    $sortUrl = fn(string $col) => request()->fullUrlWithQuery(['sort' => $col, 'dir' => $nextDir($col), 'page' => 1]);
@endphp

<div style="margin-bottom:8px">{{ $ipAddresses->links() }}</div>

<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px"><a class="m-link-sm" href="{{ $sortUrl('id') }}">ID{{ $arrow('id') }}</a></th>
            <th><a class="m-link-sm" href="{{ $sortUrl('ip_address') }}">IP adresa{{ $arrow('ip_address') }}</a></th>
            <th><a class="m-link-sm" href="{{ $sortUrl('subnet_id') }}">Subnet{{ $arrow('subnet_id') }}</a></th>
            <th><a class="m-link-sm" href="{{ $sortUrl('member_id') }}">Člen{{ $arrow('member_id') }}</a></th>
            <th>Zařízení</th>
            <th style="width:60px">DHCP</th>
            <th style="width:70px">Gateway</th>
            <th style="width:100px">Akce</th>
        </tr>
    </thead>
    <tbody>
        @forelse($ipAddresses as $ip)
        <tr>
            <td>{{ $ip->id }}</td>
            <td style="font-family:monospace"><a class="m-link" href="{{ route('ip_addresses.show', $ip->id) }}">{{ $ip->ip_address }}</a></td>
            <td>{{ $ip->subnet?->network_address ?? '—' }}</td>
            <td>
                @if($ip->member) <a class="m-link" href="{{ route('members.show', $ip->member_id) }}">{{ $ip->member->name }}</a>
                @else — @endif
            </td>
            <td>{{ $ip->iface?->device?->name ?? '—' }}</td>
            <td>@if($ip->dhcp)<span class="m-tag m-tag-green">Ano</span>@else —@endif</td>
            <td>@if($ip->gateway)<span class="m-tag m-tag-blue">Ano</span>@else —@endif</td>
            <td>
                <div style="display:flex;gap:6px">
                    <a class="m-link-sm" href="{{ route('ip_addresses.show', $ip->id) }}">Detail</a>
                    @if($canEdit)
                    <a class="m-link-sm" href="{{ route('ip_addresses.edit', $ip->id) }}">Upravit</a>
                    @endif
                    @if($canDelete)
                    <form method="POST" action="{{ route('ip_addresses.destroy', $ip->id) }}" style="display:inline"
                          onsubmit="return confirm('Opravdu smazat IP adresu {{ addslashes($ip->ip_address) }}?')">
                        @csrf @method('DELETE')
                        <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;font-size:12px;color:#c0392b">Smazat</button>
                    </form>
                    @endif
                </div>
            </td>
        </tr>
        @empty
        <tr><td colspan="8" style="text-align:center;color:#aaa;padding:2rem">Žádné IP adresy nebyly nalezeny.</td></tr>
        @endforelse
    </tbody>
</table>
</div>

<div style="margin-top:12px">{{ $ipAddresses->links() }}</div>
</div>
@endsection
