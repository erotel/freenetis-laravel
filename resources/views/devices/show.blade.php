@extends('layouts.app')
@section('title', 'Zařízení: ' . $device->name)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('devices.index') }}">Zařízení</a> &raquo; {{ $device->name }}
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>{{ $device->name }}</h2></div>

<div class="m-actions">
    @if($canEdit)
    <a class="m-btn" href="{{ route('devices.edit', $device->id) }}">Upravit</a>
    @endif
    @if($canDelete)
    <form method="POST" action="{{ route('devices.destroy', $device->id) }}" style="display:inline"
          onsubmit="return confirm('Opravdu smazat zařízení {{ addslashes($device->name) }}?')">
        @csrf @method('DELETE')
        <button class="m-btn m-btn-danger" type="submit">Smazat</button>
    </form>
    @endif
</div>

<div class="m-grid2">
    <div class="m-card">
        <div class="m-card-title">Informace o zařízení</div>
        <div class="m-field"><span class="m-field-label">ID</span><span class="m-field-value">{{ $device->id }}</span></div>
        <div class="m-field"><span class="m-field-label">Název</span><span class="m-field-value">{{ $device->name }}</span></div>
        <div class="m-field"><span class="m-field-label">Typ</span><span class="m-field-value">{{ $device->enumType?->value ?? '—' }}</span></div>
        @if($device->trade_name)
        <div class="m-field"><span class="m-field-label">Obchodní název</span><span class="m-field-value">{{ $device->trade_name }}</span></div>
        @endif
        <div class="m-field">
            <span class="m-field-label">Uživatel</span>
            <span class="m-field-value">
                @if($device->user) <a href="{{ route('users.show', $device->user_id) }}">{{ $device->user->full_name }}</a>
                @else —
                @endif
            </span>
        </div>
        <div class="m-field">
            <span class="m-field-label">Člen</span>
            <span class="m-field-value">
                @if($device->user?->member) <a href="{{ route('members.show', $device->user->member_id) }}">{{ $device->user->member->name }}</a>
                @else —
                @endif
            </span>
        </div>
        @if($device->addressPoint)
        <div class="m-field">
            <span class="m-field-label">Adresa umístění</span>
            <span class="m-field-value">
                @if($device->addressPoint->street){{ $device->addressPoint->street->street }} {{ $device->addressPoint->street_number }}, @endif
                @if($device->addressPoint->town){{ $device->addressPoint->town->town }} {{ $device->addressPoint->town->zip_code }}@endif
            </span>
        </div>
        @endif
        @if($device->operating_system !== null)
        <div class="m-field"><span class="m-field-label">Operační systém</span><span class="m-field-value">{{ $device->operating_system }}</span></div>
        @endif
        @if($canViewLogin)
        <div class="m-field"><span class="m-field-label">Login</span><span class="m-field-value">{{ $device->login ?? '—' }}</span></div>
        @endif
        @if($canViewPassword)
        <div class="m-field"><span class="m-field-label">Heslo</span><span class="m-field-value">{{ $device->password ?? '—' }}</span></div>
        @endif
        <div class="m-field"><span class="m-field-label">Poslední přístup</span><span class="m-field-value">{{ $device->access_time ?? '—' }}</span></div>
        @if($device->price !== null)
        <div class="m-field"><span class="m-field-label">Cena</span><span class="m-field-value">{{ $device->price }}</span></div>
        @endif
        @if($device->payment_rate !== null)
        <div class="m-field"><span class="m-field-label">Měsíční splátka</span><span class="m-field-value">{{ $device->payment_rate }}</span></div>
        @endif
        @if($device->buy_date && $device->buy_date !== '0000-00-00')
        <div class="m-field"><span class="m-field-label">Datum koupě</span><span class="m-field-value">{{ $device->buy_date }}</span></div>
        @endif
        @if($device->comment)
        <div class="m-field"><span class="m-field-label">Komentář</span><span class="m-field-value">{{ $device->comment }}</span></div>
        @endif
    </div>

    {{-- Technici --}}
    @if($device->deviceEngineers->count() > 0 || $canManageEngineers)
    <div class="m-card">
        <div class="m-card-title">Technici zařízení</div>
        @forelse($device->deviceEngineers as $de)
        <div class="m-field">
            <span class="m-field-label">
                @if($de->user) <a class="m-link" href="{{ route('users.show', $de->user_id) }}">{{ $de->user->login }}</a>
                @else — @endif
            </span>
            <span class="m-field-value" style="display:flex;gap:8px;align-items:center">
                {{ trim(($de->user->name ?? '') . ' ' . ($de->user->surname ?? '')) ?: '—' }}
                @if($canDeleteEngineer)
                <form method="POST" action="{{ route('devices.engineers.remove', [$device->id, $de->user_id]) }}" style="display:inline">
                    @csrf @method('DELETE')
                    <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;font-size:12px;color:#c0392b"
                            onclick="return confirm('Odebrat technika?')">Odebrat</button>
                </form>
                @endif
            </span>
        </div>
        @empty
        <div style="font-size:13px;color:#aaa;padding:6px 0">Žádní technici.</div>
        @endforelse
        @if($canManageEngineers)
        <form method="POST" action="{{ route('devices.engineers.add', $device->id) }}" style="margin-top:10px;display:flex;gap:8px">
            @csrf
            <select class="m-form-select" name="user_id" required>
                <option value="">— vyberte technika —</option>
                @foreach($engineerUsers as $u)
                    <option value="{{ $u->id }}">{{ $u->login }} ({{ trim($u->name . ' ' . $u->surname) }})</option>
                @endforeach
            </select>
            <button class="m-btn m-btn-success" type="submit">+ Přidat</button>
        </form>
        @endif
    </div>
    @else
    <div></div>
    @endif
</div>

{{-- Rozhraní --}}
<div class="m-section">
    Rozhraní
    @if($canEditDevice)
    <a class="m-link-sm" href="{{ route('ifaces.create', $device->id) }}" style="margin-left:10px">+ Přidat rozhraní</a>
    @endif
</div>
<div class="m-card" style="padding:0;overflow-x:auto;margin-bottom:16px">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th>Typ</th>
            <th>Jméno</th>
            <th>MAC</th>
            <th>IP adresy</th>
            @if($canEditDevice)<th style="width:80px">Akce</th>@endif
        </tr>
    </thead>
    <tbody>
        @forelse($device->ifaces as $iface)
        <tr>
            <td>{{ $iface->type ?? '—' }}</td>
            <td><a class="m-link" href="{{ route('ifaces.show', $iface->id) }}">{{ $iface->name ?? '—' }}</a></td>
            <td style="font-family:monospace;font-size:12px">{{ $iface->mac ?? '—' }}</td>
            <td>
                @forelse($iface->ipAddresses as $ip)
                    <a class="m-link" href="{{ route('ip_addresses.show', $ip->id) }}">{{ $ip->ip_address }}</a>@if(!$loop->last), @endif
                @empty —
                @endforelse
            </td>
            @if($canEditDevice)
            <td>
                <div style="display:flex;gap:6px">
                    <a class="m-link-sm" href="{{ route('ifaces.edit', $iface->id) }}">Upravit</a>
                    <form method="POST" action="{{ route('ifaces.destroy', $iface->id) }}" style="display:inline"
                          onsubmit="return confirm('Smazat rozhraní {{ addslashes($iface->name) }}?')">
                        @csrf @method('DELETE')
                        <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;font-size:12px;color:#c0392b">Smazat</button>
                    </form>
                </div>
            </td>
            @endif
        </tr>
        @empty
        <tr><td colspan="{{ $canEditDevice ? 5 : 4 }}" style="text-align:center;color:#aaa;padding:1.5rem">Žádná rozhraní.</td></tr>
        @endforelse
    </tbody>
</table>
</div>

{{-- IPv6 --}}
@php $ip6Rows = $device->ifaces->flatMap(fn($i) => $i->ip6Addresses->map(fn($a) => ['iface' => $i, 'addr' => $a])); @endphp
@if($ip6Rows->isNotEmpty())
<div class="m-section">IPv6 adresy</div>
<div class="m-card" style="padding:0;overflow-x:auto;margin-bottom:16px">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr><th>IPv6 adresa</th><th>Rozhraní</th></tr>
    </thead>
    <tbody>
        @foreach($ip6Rows as $row)
        <tr>
            <td style="font-family:monospace">{{ $row['addr']->ip_address }}</td>
            <td><a class="m-link" href="{{ route('ifaces.show', $row['iface']->id) }}">{{ $row['iface']->name }}</a></td>
        </tr>
        @endforeach
    </tbody>
</table>
</div>
@endif

</div>
@endsection
