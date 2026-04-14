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
    <h3>
        Rozhraní
        @if($canEditDevice)
            <a href="{{ route('ifaces.create', $device->id) }}" style="font-size:12px; margin-left:10px;">+ Přidat rozhraní</a>
        @endif
    </h3>
    @if($device->ifaces->count() > 0)
        <table class="extended" cellspacing="0">
            <thead>
                <tr>
                    <th>Typ</th>
                    <th>Jméno</th>
                    <th>MAC</th>
                    <th>IP adresy</th>
                    @if($canEditDevice)
                        <th>Akce</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach($device->ifaces as $iface)
                    <tr>
                        <td>{{ $iface->type ?? '—' }}</td>
                        <td><a href="{{ route('ifaces.show', $iface->id) }}">{{ $iface->name ?? '—' }}</a></td>
                        <td>{{ $iface->mac ?? '—' }}</td>
                        <td>
                            @forelse($iface->ipAddresses as $ip)
                                <a href="{{ route('ip_addresses.show', $ip->id) }}">{{ $ip->ip_address }}</a>@if(!$loop->last), @endif
                            @empty
                                —
                            @endforelse
                        </td>
                        @if($canEditDevice)
                            <td>
                                <a href="{{ route('ifaces.edit', $iface->id) }}" title="Upravit">
                                    <img src="{{ asset('media/images/icons/gtk_edit.png') }}" alt="Upravit">
                                </a>
                                <form method="POST" action="{{ route('ifaces.destroy', $iface->id) }}"
                                      style="display:inline"
                                      onsubmit="return confirm('Smazat rozhraní {{ addslashes($iface->name) }}?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" style="border:none;background:none;cursor:pointer;padding:0" title="Smazat">
                                        <img src="{{ asset('media/images/icons/delete.png') }}" alt="Smazat">
                                    </button>
                                </form>
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- IPv6 adresy --}}
    @php $ip6Rows = $device->ifaces->flatMap(fn($i) => $i->ip6Addresses->map(fn($a) => ['iface' => $i, 'addr' => $a])); @endphp
    @if($ip6Rows->isNotEmpty())
        <h3>IPv6 adresy</h3>
        <table class="extended" cellspacing="0">
            <thead>
                <tr>
                    <th>IPv6 adresa</th>
                    <th>Rozhraní</th>
                </tr>
            </thead>
            <tbody>
                @foreach($ip6Rows as $row)
                    <tr>
                        <td>{{ $row['addr']->ip_address }}</td>
                        <td><a href="{{ route('ifaces.show', $row['iface']->id) }}">{{ $row['iface']->name }}</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- Technici zařízení --}}
    @if($device->deviceEngineers->count() > 0 || $canManageEngineers)
    <h3>Technici zařízení</h3>
    <table class="extended" cellspacing="0">
        <thead>
            <tr>
                <th>Login</th>
                <th>Jméno</th>
                @if($canDeleteEngineer) <th>Akce</th> @endif
            </tr>
        </thead>
        <tbody>
            @forelse($device->deviceEngineers as $de)
                <tr>
                    <td>
                        @if($de->user)
                            <a href="{{ route('users.show', $de->user_id) }}">{{ $de->user->login }}</a>
                        @else
                            —
                        @endif
                    </td>
                    <td>{{ trim(($de->user->name ?? '') . ' ' . ($de->user->surname ?? '')) ?: '—' }}</td>
                    @if($canDeleteEngineer)
                    <td>
                        <form method="POST"
                              action="{{ route('devices.engineers.remove', [$device->id, $de->user_id]) }}"
                              style="display:inline">
                            @csrf @method('DELETE')
                            <button type="submit"
                                    style="background:none;border:none;cursor:pointer;padding:0;color:#c00;text-decoration:underline;"
                                    onclick="return confirm('Odebrat technika?')">Odebrat</button>
                        </form>
                    </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ $canDeleteEngineer ? 3 : 2 }}" style="color:#888;">Žádní technici.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if($canManageEngineers)
    <form method="POST" action="{{ route('devices.engineers.add', $device->id) }}" style="margin-top:6px;">
        @csrf
        <select name="user_id" required style="width:220px;">
            <option value="">— vyberte technika —</option>
            @foreach($engineerUsers as $u)
                <option value="{{ $u->id }}">{{ $u->login }} ({{ trim($u->name . ' ' . $u->surname) }})</option>
            @endforeach
        </select>
        <button type="submit" style="margin-left:4px;">+ Přidat technika</button>
    </form>
    @endif
    @endif
@endsection
