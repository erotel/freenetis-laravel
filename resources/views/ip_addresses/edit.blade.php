@extends('layouts.app')

@section('title', 'Upravit IP adresu: ' . $ip->ip_address)

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('ip_addresses.index') }}">IP adresy</a> &raquo;
        <a href="{{ route('ip_addresses.show', $ip->id) }}">{{ $ip->ip_address }}</a> &raquo;
        Upravit
    </div>
@endsection

@section('content')
    <h2>Upravit IP adresu: {{ $ip->ip_address }}</h2>

    <form method="POST" action="{{ route('ip_addresses.update', $ip->id) }}" class="form">
        @csrf
        @method('PUT')

        <table class="extended" cellspacing="0">
            <tbody>
                <tr>
                    <th><label for="ip_address">IP adresa <span class="required">*</span></label></th>
                    <td>
                        <input type="text" id="ip_address" name="ip_address"
                               value="{{ old('ip_address', $ip->ip_address) }}" maxlength="15">
                        @error('ip_address') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="subnet_id">Subnet <span class="required">*</span></label></th>
                    <td>
                        <select id="subnet_id" name="subnet_id">
                            <option value="">— vyberte subnet —</option>
                            @foreach($subnets as $subnet)
                                <option value="{{ $subnet->id }}"
                                    @selected(old('subnet_id', $ip->subnet_id) == $subnet->id)>
                                    {{ $subnet->label }}
                                </option>
                            @endforeach
                        </select>
                        @error('subnet_id') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="iface_id">Rozhraní</label></th>
                    <td>
                        <select id="iface_id" name="iface_id">
                            <option value="">— žádné —</option>
                            @foreach($ifaces->groupBy(fn($i) => $i->device?->name ?? '(bez zařízení)') as $deviceName => $deviceIfaces)
                                <optgroup label="{{ $deviceName }}">
                                    @foreach($deviceIfaces as $iface)
                                        <option value="{{ $iface->id }}"
                                            @selected(old('iface_id', $ip->iface_id) == $iface->id)>
                                            {{ $iface->name ?? 'iface #' . $iface->id }}
                                        </option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                        @error('iface_id') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="member_id">Člen</label></th>
                    <td>
                        <select id="member_id" name="member_id">
                            <option value="">— žádný —</option>
                            @foreach($members as $member)
                                <option value="{{ $member->id }}"
                                    @selected(old('member_id', $ip->member_id) == $member->id)>
                                    {{ $member->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('member_id') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th>DHCP</th>
                    <td>
                        <input type="checkbox" id="dhcp" name="dhcp" value="1"
                               @checked(old('dhcp', $ip->dhcp))>
                        <label for="dhcp">Přidělit přes DHCP</label>
                    </td>
                </tr>
                <tr>
                    <th>Gateway</th>
                    <td>
                        <input type="checkbox" id="gateway" name="gateway" value="1"
                               @checked(old('gateway', $ip->gateway))>
                        <label for="gateway">Je výchozí brána subnetu</label>
                        @error('gateway') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th>Služba</th>
                    <td>
                        <input type="checkbox" id="service" name="service" value="1"
                               @checked(old('service', $ip->service))>
                        <label for="service">Servisní adresa</label>
                    </td>
                </tr>
            </tbody>
        </table>

        <p><input type="submit" value="Uložit"></p>
    </form>
@endsection
