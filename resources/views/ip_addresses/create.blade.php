@extends('layouts.app')

@section('title', 'Přidat IP adresu')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('ip_addresses.index') }}">IP adresy</a> &raquo;
        Přidat IP adresu
    </div>
@endsection

@section('content')
    <h2>Přidat IP adresu</h2>

    <form method="POST" action="{{ route('ip_addresses.store') }}" class="form">
        @csrf

        <table class="extended" cellspacing="0">
            <tbody>
                <tr>
                    <th><label for="ip_address">IP adresa <span class="required">*</span></label></th>
                    <td>
                        <input type="text" id="ip_address" name="ip_address"
                               value="{{ old('ip_address') }}" maxlength="15" placeholder="192.168.1.x">
                        @error('ip_address') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="subnet_id">Subnet <span class="required">*</span></label></th>
                    <td>
                        <select id="subnet_id" name="subnet_id">
                            <option value="">— vyberte subnet —</option>
                            @foreach($subnets as $subnet)
                                <option value="{{ $subnet->id }}" @selected(old('subnet_id') == $subnet->id)>
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
                                            @selected(old('iface_id', $preselectedIfaceId) == $iface->id)>
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
                                    @selected(old('member_id', $preselectedMemberId) == $member->id)>
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
                               @checked(old('dhcp'))>
                        <label for="dhcp">Přidělit přes DHCP</label>
                        @error('dhcp') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th>Gateway</th>
                    <td>
                        <input type="checkbox" id="gateway" name="gateway" value="1"
                               @checked(old('gateway'))>
                        <label for="gateway">Je výchozí brána subnetu</label>
                        @error('gateway') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th>Služba</th>
                    <td>
                        <input type="checkbox" id="service" name="service" value="1"
                               @checked(old('service'))>
                        <label for="service">Servisní adresa</label>
                        @error('service') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
            </tbody>
        </table>

        <p><input type="submit" value="Přidat"></p>
    </form>
@endsection
