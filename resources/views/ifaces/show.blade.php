@extends('layouts.app')

@section('title', 'Rozhraní: ' . ($iface->name ?: $iface->mac ?: '#' . $iface->id))

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('ifaces.index') }}">Rozhraní</a> &raquo;
        {{ $iface->name ?: ($iface->mac ?: '#' . $iface->id) }}
    </div>
@endsection

@section('content')
    <h2>Rozhraní: {{ $iface->name ?: ($iface->mac ?: '#' . $iface->id) }}</h2>

    <div style="margin-bottom:1em;">
        @if($iface->device)
            <a href="{{ route('devices.show', $iface->device_id) }}">&larr; Zpět na zařízení {{ $iface->device->name }}</a>
        @endif
    </div>

    <table class="extended" cellspacing="0">
        <thead>
            <tr><th colspan="2">Informace o rozhraní</th></tr>
        </thead>
        <tbody>
            <tr>
                <th>ID</th>
                <td>{{ $iface->id }}</td>
            </tr>
            <tr>
                <th>Typ</th>
                <td>{{ $iface->type_label }}</td>
            </tr>
            <tr>
                <th>Název</th>
                <td>{{ $iface->name ?? '—' }}</td>
            </tr>
            @if($iface->mac)
                <tr>
                    <th>MAC adresa</th>
                    <td>{{ $iface->mac }}</td>
                </tr>
            @endif
            @if($iface->type === \App\Models\Iface::PORT && $iface->number !== null)
                <tr>
                    <th>Číslo portu</th>
                    <td>{{ $iface->number }}</td>
                </tr>
            @endif
            @if($iface->comment)
                <tr>
                    <th>Komentář</th>
                    <td>{{ $iface->comment }}</td>
                </tr>
            @endif
            <tr>
                <th>Zařízení</th>
                <td>
                    @if($iface->device)
                        <a href="{{ route('devices.show', $iface->device_id) }}">{{ $iface->device->name }}</a>
                    @else
                        —
                    @endif
                </td>
            </tr>
            <tr>
                <th>Člen</th>
                <td>
                    @if($iface->device?->user?->member)
                        <a href="{{ route('members.show', $iface->device->user->member_id) }}">{{ $iface->device->user->member->name }}</a>
                    @else
                        —
                    @endif
                </td>
            </tr>
        </tbody>
    </table>

    {{-- IP adresy --}}
    @if($iface->ipAddresses->count() > 0)
        <h3>IP adresy</h3>
        <table class="extended" cellspacing="0">
            <thead>
                <tr>
                    <th>IP adresa</th>
                    <th>Subnet</th>
                    <th>DHCP</th>
                    <th>Gateway</th>
                </tr>
            </thead>
            <tbody>
                @foreach($iface->ipAddresses as $ip)
                    <tr>
                        <td>
                            <a href="{{ route('ip_addresses.show', $ip->id) }}">{{ $ip->ip_address }}</a>
                        </td>
                        <td>{{ $ip->subnet?->label ?? '—' }}</td>
                        <td>{{ $ip->dhcp ? '✓' : '—' }}</td>
                        <td>{{ $ip->gateway ? '✓' : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- VLANy --}}
    @if($iface->vlans->count() > 0)
        <h3>VLANy</h3>
        <table class="extended" cellspacing="0">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Název</th>
                    <th>Tag 802.1q</th>
                </tr>
            </thead>
            <tbody>
                @foreach($iface->vlans as $vlan)
                    <tr>
                        <td>{{ $vlan->id }}</td>
                        <td><a href="{{ route('vlans.show', $vlan->id) }}">{{ $vlan->name }}</a></td>
                        <td>{{ $vlan->tag_802_1q }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
