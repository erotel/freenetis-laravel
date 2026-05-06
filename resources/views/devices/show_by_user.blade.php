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
<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0;table-layout:auto">
    <thead>
        <tr style="white-space:nowrap">
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
            $firstIface  = $device->ifaces->first();
            $firstIp     = $firstIface?->ipAddresses->first();
            $subnet      = $firstIp?->subnet;
            $subnetLabel = $subnet ? ($subnet->name ?? $subnet->network_address) : '—';
        @endphp
        <tr>
            <td>{{ $device->id }}</td>
            <td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                <a class="m-link" href="{{ route('devices.show', $device->id) }}">{{ $device->name }}</a>
            </td>
            <td>{{ $device->enumType?->value ?? '—' }}</td>
            <td style="font-family:monospace;font-size:14px">{{ $firstIface?->mac ?? '—' }}</td>
            <td style="font-family:monospace;font-size:14px">{{ $firstIp?->ip_address ?? '—' }}</td>
            <td>{{ $subnetLabel }}</td>
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
@endif
</div>
@endsection
