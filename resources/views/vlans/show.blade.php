@extends('layouts.app')

@section('title', 'VLAN: ' . $vlan)

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('vlans.index') }}">VLANy</a> &raquo;
        {{ (string)$vlan }}
    </div>
@endsection

@section('content')
    <h2>{{ (string)$vlan }}</h2>

    @if(session('success'))
        <p class="success">{{ session('success') }}</p>
    @endif
    @if(session('error'))
        <p class="error">{{ session('error') }}</p>
    @endif

    <div style="margin-bottom:1em;">
        @if($canEdit)
            <a href="{{ route('vlans.edit', $vlan->id) }}">
                <img src="{{ asset('media/images/icons/gtk_edit.png') }}" alt="Upravit">
                Upravit
            </a>
            &nbsp;
        @endif
        @if($canDelete && $vlan->tag_802_1q !== \App\Models\Vlan::DEFAULT_VLAN_TAG)
            <form method="POST" action="{{ route('vlans.destroy', $vlan->id) }}" style="display:inline;"
                  onsubmit="return confirm('Opravdu smazat VLAN {{ addslashes((string)$vlan) }}?');">
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
            <tr><th colspan="2">Informace o VLAN</th></tr>
        </thead>
        <tbody>
            <tr>
                <th>ID</th>
                <td>{{ $vlan->id }}</td>
            </tr>
            <tr>
                <th>Název</th>
                <td>{{ $vlan->name ?: '—' }}</td>
            </tr>
            <tr>
                <th>802.1Q tag</th>
                <td>{{ $vlan->tag_802_1q }}</td>
            </tr>
            <tr>
                <th>Komentář</th>
                <td>{{ $vlan->comment ?: '—' }}</td>
            </tr>
        </tbody>
    </table>

    <h3>Přiřazená rozhraní</h3>

    @if($vlan->ifaces->isEmpty())
        <p>Žádná rozhraní.</p>
    @else
        <table class="extended" cellspacing="0">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Rozhraní</th>
                    <th>Zařízení</th>
                    <th>Tagged</th>
                </tr>
            </thead>
            <tbody>
                @foreach($vlan->ifaces as $iface)
                    <tr>
                        <td>{{ $iface->id }}</td>
                        <td>{{ $iface->name ?? 'iface #' . $iface->id }}</td>
                        <td>
                            @if($iface->device)
                                @if($canViewDevices)
                                    <a href="{{ route('devices.show', $iface->device_id) }}">{{ $iface->device->name }}</a>
                                @else
                                    {{ $iface->device->name }}
                                @endif
                            @else
                                —
                            @endif
                        </td>
                        <td>{{ $iface->pivot->tagged ? '✓' : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
