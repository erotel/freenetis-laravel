@extends('layouts.app')
@section('title', 'IP adresa: ' . $ip->ip_address)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('ip_addresses.index') }}">IP adresy</a> &raquo; {{ $ip->ip_address }}
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2 style="font-family:monospace">{{ $ip->ip_address }}</h2></div>

<div class="m-actions">
    @if($canEdit) <a class="m-btn" href="{{ route('ip_addresses.edit', $ip->id) }}">Upravit</a> @endif
    @if($canDelete)
    <form method="POST" action="{{ route('ip_addresses.destroy', $ip->id) }}" style="display:inline"
          onsubmit="return confirm('Opravdu smazat IP adresu {{ addslashes($ip->ip_address) }}?')">
        @csrf @method('DELETE')
        <button class="m-btn m-btn-danger" type="submit">Smazat</button>
    </form>
    @endif
</div>

<div class="m-card" style="max-width:480px">
    <div class="m-card-title">Informace o IP adrese</div>
    <div class="m-field"><span class="m-field-label">ID</span><span class="m-field-value">{{ $ip->id }}</span></div>
    <div class="m-field"><span class="m-field-label">IP adresa</span><span class="m-field-value" style="font-family:monospace">{{ $ip->ip_address }}</span></div>
    <div class="m-field">
        <span class="m-field-label">Subnet</span>
        <span class="m-field-value">
            @if($ip->subnet)
                <a href="{{ route('subnets.show', $ip->subnet_id) }}">{{ $ip->subnet->label }}</a>
            @else — @endif
        </span>
    </div>
    <div class="m-field">
        <span class="m-field-label">Člen</span>
        <span class="m-field-value">
            @if($ip->member) <a href="{{ route('members.show', $ip->member_id) }}">{{ $ip->member->name }}</a>
            @else — @endif
        </span>
    </div>
    <div class="m-field">
        <span class="m-field-label">Zařízení</span>
        <span class="m-field-value">
            @if($ip->iface?->device) <a href="{{ route('devices.show', $ip->iface->device_id) }}">{{ $ip->iface->device->name }}</a>
            @else — @endif
        </span>
    </div>
    <div class="m-field"><span class="m-field-label">Rozhraní</span><span class="m-field-value">{{ $ip->iface?->name ?? '—' }}</span></div>
    <div class="m-field">
        <span class="m-field-label">DHCP</span>
        <span class="m-field-value">@if($ip->dhcp)<span class="m-tag m-tag-green">Ano</span>@else Ne @endif</span>
    </div>
    <div class="m-field">
        <span class="m-field-label">Gateway</span>
        <span class="m-field-value">@if($ip->gateway)<span class="m-tag m-tag-blue">Ano</span>@else Ne @endif</span>
    </div>
    <div class="m-field">
        <span class="m-field-label">Služba</span>
        <span class="m-field-value">@if($ip->service)<span class="m-tag m-tag-amber">Ano</span>@else Ne @endif</span>
    </div>
</div>
</div>
@endsection
