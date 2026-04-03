@extends('layouts.app')

@section('title', 'Přidat subnet')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('subnets.index') }}">Subnety</a> &raquo;
        Přidat subnet
    </div>
@endsection

@section('content')
    <h2>Přidat subnet</h2>

    <form method="POST" action="{{ route('subnets.store') }}" class="form">
        @csrf

        <table class="extended" cellspacing="0">
            <tbody>
                <tr>
                    <th><label for="name">Název</label></th>
                    <td>
                        <input type="text" id="name" name="name" value="{{ old('name') }}" maxlength="254">
                        @error('name') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="network_address">Síťová adresa <span class="required">*</span></label></th>
                    <td>
                        <input type="text" id="network_address" name="network_address"
                               value="{{ old('network_address') }}" maxlength="15" placeholder="192.168.1.0">
                        @error('network_address') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="netmask">Maska sítě <span class="required">*</span></label></th>
                    <td>
                        <input type="text" id="netmask" name="netmask"
                               value="{{ old('netmask') }}" maxlength="15" placeholder="255.255.255.0">
                        @error('netmask') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                @if($canSetDhcp)
                    <tr>
                        <th>DHCP</th>
                        <td>
                            <input type="checkbox" id="dhcp" name="dhcp" value="1" @checked(old('dhcp'))>
                            <label for="dhcp">Aktivovat DHCP server</label>
                        </td>
                    </tr>
                @endif
                @if($canSetDns)
                    <tr>
                        <th>DNS</th>
                        <td>
                            <input type="checkbox" id="dns" name="dns" value="1" @checked(old('dns'))>
                            <label for="dns">Aktivovat DNS server</label>
                        </td>
                    </tr>
                @endif
                @if($canSetQos)
                    <tr>
                        <th>QoS</th>
                        <td>
                            <input type="checkbox" id="qos" name="qos" value="1" @checked(old('qos'))>
                            <label for="qos">Aktivovat QoS</label>
                        </td>
                    </tr>
                @endif
            </tbody>
        </table>

        <p><input type="submit" value="Přidat"></p>
    </form>
@endsection
