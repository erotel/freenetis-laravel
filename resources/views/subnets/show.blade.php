@extends('layouts.app')

@section('title', 'Subnet: ' . $subnet->label)

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('subnets.index') }}">Subnety</a> &raquo;
        {{ $subnet->label }}
    </div>
@endsection

@section('content')
    <h2>{{ $subnet->label }}</h2>

    <div style="margin-bottom:1em;">
        @if($canEdit)
            <a href="{{ route('subnets.edit', $subnet->id) }}">
                <img src="{{ asset('media/images/icons/gtk_edit.png') }}" alt="Upravit">
                Upravit
            </a>
            &nbsp;
        @endif
        @if($canDelete)
            <form method="POST" action="{{ route('subnets.destroy', $subnet->id) }}" style="display:inline;"
                  onsubmit="return confirm('Opravdu smazat subnet {{ addslashes($subnet->network_address) }}?');">
                @csrf
                @method('DELETE')
                <button type="submit" class="icon-button" title="Smazat">
                    <img src="{{ asset('media/images/icons/delete.png') }}" alt="Smazat">
                    Smazat
                </button>
            </form>
        @endif
    </div>

    <table class="extended" cellspacing="0">
        <thead>
            <tr><th colspan="2">Informace o subnetu</th></tr>
        </thead>
        <tbody>
            <tr>
                <th>ID</th>
                <td>{{ $subnet->id }}</td>
            </tr>
            <tr>
                <th>Název</th>
                <td>{{ $subnet->name ?: '—' }}</td>
            </tr>
            <tr>
                <th>Síť</th>
                <td>{{ $subnet->network_address }}</td>
            </tr>
            <tr>
                <th>Maska</th>
                <td>{{ $subnet->netmask }}</td>
            </tr>
            @if($showDhcp)
                <tr>
                    <th>DHCP</th>
                    <td>{{ $subnet->dhcp ? 'Ano' : 'Ne' }}</td>
                </tr>
            @endif
            @if($showDns)
                <tr>
                    <th>DNS</th>
                    <td>{{ $subnet->dns ? 'Ano' : 'Ne' }}</td>
                </tr>
            @endif
            @if($showQos)
                <tr>
                    <th>QoS</th>
                    <td>{{ $subnet->qos ? 'Ano' : 'Ne' }}</td>
                </tr>
            @endif
            <tr>
                <th>Redirect</th>
                <td>{{ $subnet->redirect ? 'Ano' : 'Ne' }}</td>
            </tr>
        </tbody>
    </table>

    <h3>IP adresy v subnetu</h3>

    @if($subnet->ipAddresses->isEmpty())
        <p>Žádné IP adresy.</p>
    @else
        <table class="extended" cellspacing="0">
            <thead>
                <tr>
                    <th>IP adresa</th>
                    <th>Člen</th>
                    <th>Zařízení</th>
                    <th>DHCP</th>
                    <th>Gateway</th>
                    <th>Akce</th>
                </tr>
            </thead>
            <tbody>
                @foreach($subnet->ipAddresses as $ip)
                    <tr>
                        <td>
                            <a href="{{ route('ip_addresses.show', $ip->id) }}">{{ $ip->ip_address }}</a>
                        </td>
                        <td>
                            @if($ip->member)
                                <a href="{{ route('members.show', $ip->member_id) }}">{{ $ip->member->name }}</a>
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            @if($ip->iface?->device)
                                <a href="{{ route('devices.show', $ip->iface->device_id) }}">{{ $ip->iface->device->name }}</a>
                            @else
                                —
                            @endif
                        </td>
                        <td>{{ $ip->dhcp ? '✓' : '—' }}</td>
                        <td>{{ $ip->gateway ? '✓' : '—' }}</td>
                        <td>
                            <a href="{{ route('ip_addresses.show', $ip->id) }}" title="Detail">
                                <img src="{{ asset('media/images/icons/con_info.png') }}" alt="Detail">
                            </a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
