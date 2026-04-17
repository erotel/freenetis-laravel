@extends('layouts.app')
@section('title', 'Zařízení')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs"><a href="{{ route('devices.index') }}">Zařízení</a></div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Seznam zařízení</h2></div>
<div class="m-subtitle">Celkem: {{ $devices->total() }} záznamů</div>

<div class="m-card" style="margin-bottom:16px;padding:14px 1.25rem">
    <form method="GET" action="{{ route('devices.index') }}" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
        <div>
            <div class="m-form-label">Hledat</div>
            <input class="m-form-input" style="width:200px" type="text" name="search"
                   value="{{ $search }}" placeholder="Podle názvu...">
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
            @if($search) <a class="m-btn" href="{{ route('devices.index') }}">Zrušit filtr</a> @endif
        </div>
        @if(request('sort'))<input type="hidden" name="sort" value="{{ $sort }}">@endif
        @if(request('dir'))<input type="hidden" name="dir" value="{{ $dir }}">@endif
    </form>
</div>

@if($canNew)
<div class="m-actions">
    <a class="m-btn m-btn-success" href="{{ route('devices.create') }}">+ Přidat zařízení</a>
</div>
@endif

@php
    $nextDir = fn(string $col) => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    $arrow   = fn(string $col) => $sort === $col ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
    $sortUrl = fn(string $col) => request()->fullUrlWithQuery(['sort' => $col, 'dir' => $nextDir($col), 'page' => 1]);
@endphp

<div style="margin-bottom:8px">{{ $devices->links() }}</div>

<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px"><a class="m-link-sm" href="{{ $sortUrl('id') }}">ID{{ $arrow('id') }}</a></th>
            <th style="text-align:left"><a class="m-link-sm" href="{{ $sortUrl('name') }}">Název{{ $arrow('name') }}</a></th>
            <th style="width:130px"><a class="m-link-sm" href="{{ $sortUrl('type') }}">Typ{{ $arrow('type') }}</a></th>
            <th style="width:160px"><a class="m-link-sm" href="{{ $sortUrl('user_id') }}">Uživatel{{ $arrow('user_id') }}</a></th>
            <th style="width:140px"><a class="m-link-sm" href="{{ $sortUrl('access_time') }}">Poslední přístup{{ $arrow('access_time') }}</a></th>
            <th style="width:80px">Akce</th>
        </tr>
    </thead>
    <tbody>
        @forelse($devices as $device)
        <tr>
            <td>{{ $device->id }}</td>
            <td style="text-align:left"><a class="m-link" href="{{ route('devices.show', $device->id) }}">{{ $device->name }}</a></td>
            <td>{{ $device->enumType?->value ?? '—' }}</td>
            <td>
                @if($device->user)
                    <a class="m-link" href="{{ route('users.show', $device->user_id) }}">{{ $device->user->login }}</a>
                @else —
                @endif
            </td>
            <td style="font-size:12px;color:#888">{{ $device->access_time ?? '—' }}</td>
            <td>
                <div style="display:flex;gap:6px">
                    <a class="m-link-sm" href="{{ route('devices.show', $device->id) }}">Detail</a>
                    @if($canEdit)
                    <a class="m-link-sm" href="{{ route('devices.edit', $device->id) }}">Upravit</a>
                    @endif
                    @if($canDelete)
                    <form method="POST" action="{{ route('devices.destroy', $device->id) }}" style="display:inline"
                          onsubmit="return confirm('Opravdu smazat zařízení {{ addslashes($device->name) }}?')">
                        @csrf @method('DELETE')
                        <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;font-size:12px;color:#c0392b">Smazat</button>
                    </form>
                    @endif
                </div>
            </td>
        </tr>
        @empty
        <tr><td colspan="6" style="text-align:center;color:#aaa;padding:2rem">Žádná zařízení nebyla nalezena.</td></tr>
        @endforelse
    </tbody>
</table>
</div>

<div style="margin-top:12px">{{ $devices->links() }}</div>
</div>
@endsection
