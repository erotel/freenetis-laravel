@extends('layouts.app')
@section('title', 'Rozhraní: ' . ($iface->name ?: $iface->mac ?: '#' . $iface->id))
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('ifaces.index') }}">Rozhraní</a> &raquo;
    {{ $iface->name ?: ($iface->mac ?: '#' . $iface->id) }}
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Rozhraní: {{ $iface->name ?: ($iface->mac ?: '#' . $iface->id) }}</h2></div>

@if($iface->device)
<div class="m-actions">
    <a class="m-btn" href="{{ route('devices.show', $iface->device_id) }}">&larr; Zařízení {{ $iface->device->name }}</a>
</div>
@endif

<div class="m-grid2">
    <div class="m-card">
        <div class="m-card-title">Informace o rozhraní</div>
        <div class="m-field"><span class="m-field-label">ID</span><span class="m-field-value">{{ $iface->id }}</span></div>
        <div class="m-field"><span class="m-field-label">Typ</span><span class="m-field-value">{{ $iface->type_label }}</span></div>
        <div class="m-field"><span class="m-field-label">Název</span><span class="m-field-value">{{ $iface->name ?? '—' }}</span></div>
        @if($iface->mac)
        <div class="m-field"><span class="m-field-label">MAC adresa</span><span class="m-field-value" style="font-family:monospace">{{ $iface->mac }}</span></div>
        @endif
        @if($iface->type === \App\Models\Iface::PORT && $iface->number !== null)
        <div class="m-field"><span class="m-field-label">Číslo portu</span><span class="m-field-value">{{ $iface->number }}</span></div>
        @endif
        @if($iface->comment)
        <div class="m-field"><span class="m-field-label">Komentář</span><span class="m-field-value">{{ $iface->comment }}</span></div>
        @endif
        <div class="m-field">
            <span class="m-field-label">Zařízení</span>
            <span class="m-field-value">
                @if($iface->device) <a href="{{ route('devices.show', $iface->device_id) }}">{{ $iface->device->name }}</a>
                @else — @endif
            </span>
        </div>
        <div class="m-field">
            <span class="m-field-label">Člen</span>
            <span class="m-field-value">
                @if($iface->device?->user?->member) <a href="{{ route('members.show', $iface->device->user->member_id) }}">{{ $iface->device->user->member->name }}</a>
                @else — @endif
            </span>
        </div>
    </div>
    <div></div>
</div>

@if($iface->ipAddresses->count() > 0)
<div class="m-section">IP adresy</div>
<div class="m-card" style="padding:0;overflow-x:auto;margin-bottom:16px">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th>IP adresa</th>
            <th>Subnet</th>
            <th style="width:60px">DHCP</th>
            <th style="width:70px">Gateway</th>
        </tr>
    </thead>
    <tbody>
        @foreach($iface->ipAddresses as $ip)
        <tr>
            <td><a class="m-link" href="{{ route('ip_addresses.show', $ip->id) }}">{{ $ip->ip_address }}</a></td>
            <td>{{ $ip->subnet?->label ?? '—' }}</td>
            <td>@if($ip->dhcp)<span class="m-tag m-tag-green">Ano</span>@else —@endif</td>
            <td>@if($ip->gateway)<span class="m-tag m-tag-blue">Ano</span>@else —@endif</td>
        </tr>
        @endforeach
    </tbody>
</table>
</div>
@endif

@if($iface->vlans->count() > 0)
<div class="m-section">VLANy</div>
<div class="m-card" style="padding:0;overflow-x:auto;margin-bottom:16px">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:60px">ID</th>
            <th>Název</th>
            <th style="width:100px">Tag 802.1q</th>
        </tr>
    </thead>
    <tbody>
        @foreach($iface->vlans as $vlan)
        <tr>
            <td>{{ $vlan->id }}</td>
            <td><a class="m-link" href="{{ route('vlans.show', $vlan->id) }}">{{ $vlan->name }}</a></td>
            <td>{{ $vlan->tag_802_1q }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
</div>
@endif

</div>
@endsection
