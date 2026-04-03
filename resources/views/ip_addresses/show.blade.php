@extends('layouts.app')

@section('title', 'IP adresa: ' . $ip->ip_address)

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('ip_addresses.index') }}">IP adresy</a> &raquo;
        {{ $ip->ip_address }}
    </div>
@endsection

@section('content')
    <h2>{{ $ip->ip_address }}</h2>

    @if(session('success'))
        <p class="success">{{ session('success') }}</p>
    @endif
    @if(session('error'))
        <p class="error">{{ session('error') }}</p>
    @endif

    <div style="margin-bottom:1em;">
        @if($canEdit)
            <a href="{{ route('ip_addresses.edit', $ip->id) }}">
                <img src="{{ asset('media/images/icons/gtk_edit.png') }}" alt="Upravit">
                Upravit
            </a>
            &nbsp;
        @endif
        @if($canDelete)
            <form method="POST" action="{{ route('ip_addresses.destroy', $ip->id) }}" style="display:inline;"
                  onsubmit="return confirm('Opravdu smazat IP adresu {{ addslashes($ip->ip_address) }}?');">
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
            <tr><th colspan="2">Informace o IP adrese</th></tr>
        </thead>
        <tbody>
            <tr>
                <th>ID</th>
                <td>{{ $ip->id }}</td>
            </tr>
            <tr>
                <th>IP adresa</th>
                <td>{{ $ip->ip_address }}</td>
            </tr>
            <tr>
                <th>Subnet</th>
                <td>{{ $ip->subnet?->label ?? '—' }}</td>
            </tr>
            <tr>
                <th>Člen</th>
                <td>
                    @if($ip->member)
                        <a href="{{ route('members.show', $ip->member_id) }}">{{ $ip->member->name }}</a>
                    @else
                        —
                    @endif
                </td>
            </tr>
            <tr>
                <th>Zařízení</th>
                <td>
                    @if($ip->iface?->device)
                        <a href="{{ route('devices.show', $ip->iface->device_id) }}">{{ $ip->iface->device->name }}</a>
                    @else
                        —
                    @endif
                </td>
            </tr>
            <tr>
                <th>Rozhraní</th>
                <td>{{ $ip->iface?->name ?? '—' }}</td>
            </tr>
            <tr>
                <th>DHCP</th>
                <td>{{ $ip->dhcp ? 'Ano' : 'Ne' }}</td>
            </tr>
            <tr>
                <th>Gateway</th>
                <td>{{ $ip->gateway ? 'Ano' : 'Ne' }}</td>
            </tr>
            <tr>
                <th>Služba</th>
                <td>{{ $ip->service ? 'Ano' : 'Ne' }}</td>
            </tr>
        </tbody>
    </table>
@endsection
