@extends('layouts.app')
@section('title', 'Subnet: ' . $subnet->label)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('subnets.index') }}">Subnety</a> &raquo; {{ $subnet->label }}
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>{{ $subnet->label }}</h2></div>

<div class="m-actions">
    @if($canEdit) <a class="m-btn" href="{{ route('subnets.edit', $subnet->id) }}">Upravit</a> @endif
    @if($canDelete)
    <form method="POST" action="{{ route('subnets.destroy', $subnet->id) }}" style="display:inline"
          onsubmit="return confirm('Opravdu smazat subnet {{ addslashes($subnet->network_address) }}?')">
        @csrf @method('DELETE')
        <button class="m-btn m-btn-danger" type="submit">Smazat</button>
    </form>
    @endif
</div>

<div class="m-card" style="max-width:420px;margin-bottom:16px">
    <div class="m-card-title">Informace o subnetu</div>
    <div class="m-field"><span class="m-field-label">ID</span><span class="m-field-value">{{ $subnet->id }}</span></div>
    <div class="m-field"><span class="m-field-label">Název</span><span class="m-field-value">{{ $subnet->name ?: '—' }}</span></div>
    <div class="m-field"><span class="m-field-label">Síť</span><span class="m-field-value" style="font-family:monospace">{{ $subnet->network_address }}</span></div>
    <div class="m-field"><span class="m-field-label">Maska</span><span class="m-field-value" style="font-family:monospace">{{ $subnet->netmask }}</span></div>
    @if($showDhcp)
    <div class="m-field">
        <span class="m-field-label">DHCP</span>
        <span class="m-field-value">@if($subnet->dhcp)<span class="m-tag m-tag-green">Ano</span>@else Ne @endif</span>
    </div>
    @endif
    @if($showDns)
    <div class="m-field">
        <span class="m-field-label">DNS</span>
        <span class="m-field-value">@if($subnet->dns)<span class="m-tag m-tag-green">Ano</span>@else Ne @endif</span>
    </div>
    @endif
    @if($showQos)
    <div class="m-field">
        <span class="m-field-label">QoS</span>
        <span class="m-field-value">@if($subnet->qos)<span class="m-tag m-tag-blue">Ano</span>@else Ne @endif</span>
    </div>
    @endif
    <div class="m-field">
        <span class="m-field-label">Redirect</span>
        <span class="m-field-value">@if($subnet->redirect)<span class="m-tag m-tag-amber">Ano</span>@else Ne @endif</span>
    </div>
</div>

<div class="m-section">IP adresy v subnetu</div>
@if($subnet->ipAddresses->isEmpty())
<div class="m-card"><div style="text-align:center;color:#aaa;padding:1.5rem">Žádné IP adresy.</div></div>
@else
<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th>IP adresa</th>
            <th>Člen</th>
            <th>Zařízení</th>
            <th style="width:60px">DHCP</th>
            <th style="width:70px">Gateway</th>
            <th style="width:60px">Akce</th>
        </tr>
    </thead>
    <tbody>
        @foreach($subnet->ipAddresses as $ip)
        <tr>
            <td style="font-family:monospace"><a class="m-link" href="{{ route('ip_addresses.show', $ip->id) }}">{{ $ip->ip_address }}</a></td>
            <td>
                @if($ip->member) <a class="m-link" href="{{ route('members.show', $ip->member_id) }}">{{ $ip->member->name }}</a>
                @else — @endif
            </td>
            <td>
                @if($ip->iface?->device) <a class="m-link" href="{{ route('devices.show', $ip->iface->device_id) }}">{{ $ip->iface->device->name }}</a>
                @else — @endif
            </td>
            <td>@if($ip->dhcp)<span class="m-tag m-tag-green">Ano</span>@else —@endif</td>
            <td>@if($ip->gateway)<span class="m-tag m-tag-blue">Ano</span>@else —@endif</td>
            <td><a class="m-link-sm" href="{{ route('ip_addresses.show', $ip->id) }}">Detail</a></td>
        </tr>
        @endforeach
    </tbody>
</table>
</div>
@endif

</div>
@endsection
