@extends('layouts.app')

@section('title', 'Zařízení: ' . $device->name)

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('devices.index') }}">Zařízení</a> &raquo;
        {{ $device->name }}
    </div>
@endsection

@section('content')
    <h2>{{ $device->name }}</h2>

    @if(session('success'))
        <p class="success">{{ session('success') }}</p>
    @endif
    @if(session('error'))
        <p class="error">{{ session('error') }}</p>
    @endif

    <div style="margin-bottom:1em;">
        @if($canEdit)
            <a href="{{ route('devices.edit', $device->id) }}">
                <img src="{{ asset('media/images/icons/gtk_edit.png') }}" alt="Upravit">
                Upravit
            </a>
            &nbsp;
        @endif
        @if($canDelete)
            <form method="POST" action="{{ route('devices.destroy', $device->id) }}" style="display:inline;"
                  onsubmit="return confirm('Opravdu smazat zařízení {{ addslashes($device->name) }}?');">
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
            <tr><th colspan="2">Informace o zařízení</th></tr>
        </thead>
        <tbody>
            <tr>
                <th>ID</th>
                <td>{{ $device->id }}</td>
            </tr>
            <tr>
                <th>Název</th>
                <td>{{ $device->name }}</td>
            </tr>
            <tr>
                <th>Typ</th>
                <td>{{ $device->enumType?->value ?? '—' }}</td>
            </tr>
            @if($device->trade_name)
                <tr>
                    <th>Obchodní název</th>
                    <td>{{ $device->trade_name }}</td>
                </tr>
            @endif
            <tr>
                <th>Uživatel</th>
                <td>
                    @if($device->user)
                        <a href="{{ route('users.show', $device->user_id) }}">{{ $device->user->full_name }}</a>
                    @else
                        —
                    @endif
                </td>
            </tr>
            <tr>
                <th>Člen</th>
                <td>
                    @if($device->user?->member)
                        <a href="{{ route('members.show', $device->user->member_id) }}">{{ $device->user->member->name }}</a>
                    @else
                        —
                    @endif
                </td>
            </tr>
            @if($device->addressPoint)
                <tr>
                    <th>Adresa umístění</th>
                    <td>
                        @if($device->addressPoint->street)
                            {{ $device->addressPoint->street->street }}
                            {{ $device->addressPoint->street_number }},
                        @endif
                        @if($device->addressPoint->town)
                            {{ $device->addressPoint->town->town }}
                            {{ $device->addressPoint->town->zip_code }}
                        @endif
                    </td>
                </tr>
            @endif
            @if($device->operating_system !== null)
                <tr>
                    <th>Operační systém</th>
                    <td>{{ $device->operating_system }}</td>
                </tr>
            @endif
            @if($canViewLogin)
                <tr>
                    <th>Login</th>
                    <td>{{ $device->login ?? '—' }}</td>
                </tr>
            @endif
            @if($canViewPassword)
                <tr>
                    <th>Heslo</th>
                    <td>{{ $device->password ?? '—' }}</td>
                </tr>
            @endif
            <tr>
                <th>Poslední přístup</th>
                <td>{{ $device->access_time ?? '—' }}</td>
            </tr>
            @if($device->price !== null)
                <tr>
                    <th>Cena</th>
                    <td>{{ $device->price }}</td>
                </tr>
            @endif
            @if($device->payment_rate !== null)
                <tr>
                    <th>Měsíční splátka</th>
                    <td>{{ $device->payment_rate }}</td>
                </tr>
            @endif
            @if($device->buy_date && $device->buy_date !== '0000-00-00')
                <tr>
                    <th>Datum koupě</th>
                    <td>{{ $device->buy_date }}</td>
                </tr>
            @endif
            @if($device->comment)
                <tr>
                    <th>Komentář</th>
                    <td>{{ $device->comment }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    {{-- Rozhraní --}}
    @if($device->ifaces->count() > 0)
        <h3>Rozhraní</h3>
        <table class="extended" cellspacing="0">
            <thead>
                <tr>
                    <th>Typ</th>
                    <th>Jméno</th>
                    <th>MAC</th>
                    <th>IP adresy</th>
                </tr>
            </thead>
            <tbody>
                @foreach($device->ifaces as $iface)
                    <tr>
                        <td>{{ $iface->type ?? '—' }}</td>
                        <td>{{ $iface->name ?? '—' }}</td>
                        <td>{{ $iface->mac ?? '—' }}</td>
                        <td>
                            @forelse($iface->ipAddresses as $ip)
                                <a href="{{ route('ip_addresses.show', $ip->id) }}">{{ $ip->ip_address }}</a>@if(!$loop->last), @endif
                            @empty
                                —
                            @endforelse
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- Správci zařízení --}}
    @if($device->deviceAdmins->count() > 0)
        <h3>Správci zařízení</h3>
        <table class="extended" cellspacing="0">
            <thead>
                <tr>
                    <th>Jméno</th>
                    <th>Příjmení</th>
                    <th>Login</th>
                </tr>
            </thead>
            <tbody>
                @foreach($device->deviceAdmins as $da)
                    <tr>
                        <td>{{ $da->user->name ?? '—' }}</td>
                        <td>{{ $da->user->surname ?? '—' }}</td>
                        <td>
                            @if($da->user)
                                <a href="{{ route('users.show', $da->user_id) }}">{{ $da->user->login }}</a>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- Technici zařízení --}}
    @if($device->deviceEngineers->count() > 0)
        <h3>Technici zařízení</h3>
        <table class="extended" cellspacing="0">
            <thead>
                <tr>
                    <th>Jméno</th>
                    <th>Příjmení</th>
                    <th>Login</th>
                </tr>
            </thead>
            <tbody>
                @foreach($device->deviceEngineers as $de)
                    <tr>
                        <td>{{ $de->user->name ?? '—' }}</td>
                        <td>{{ $de->user->surname ?? '—' }}</td>
                        <td>
                            @if($de->user)
                                <a href="{{ route('users.show', $de->user_id) }}">{{ $de->user->login }}</a>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
