@extends('layouts.app')

@section('title', 'Přidat rozhraní')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('devices.index') }}">Zařízení</a> &raquo;
        @if($device)
            <a href="{{ route('devices.show', $device->id) }}">{{ $device->name }}</a> &raquo;
        @endif
        Přidat rozhraní
    </div>
@endsection

@section('content')
    <h2>Přidat rozhraní</h2>

    <form method="POST" action="{{ route('ifaces.store') }}" class="form">
        @csrf

        <table class="extended" cellspacing="0">
            <tbody>
                <tr>
                    <th><label>Zařízení</label></th>
                    <td>
                        @if($device)
                            <strong>{{ $device->name }}</strong>
                            <input type="hidden" name="device_id" value="{{ $device->id }}">
                        @else
                            <input type="number" name="device_id" value="{{ old('device_id') }}" placeholder="ID zařízení">
                        @endif
                        @error('device_id') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="type">Typ <span class="required">*</span></label></th>
                    <td>
                        <select id="type" name="type">
                            @foreach($types as $value => $label)
                                <option value="{{ $value }}" @selected(old('type', 1) == $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('type') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="name">Název <span class="required">*</span></label></th>
                    <td>
                        <input type="text" id="name" name="name"
                               value="{{ old('name') }}" maxlength="254"
                               placeholder="eth0, wlan0, …">
                        @error('name') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="mac">MAC adresa</label></th>
                    <td>
                        <input type="text" id="mac" name="mac"
                               value="{{ old('mac') }}" maxlength="20"
                               placeholder="aa:bb:cc:dd:ee:ff">
                        <br><small>Povinné pro Ethernet a Wireless</small>
                        @error('mac') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="comment">Komentář</label></th>
                    <td>
                        <textarea id="comment" name="comment" maxlength="254">{{ old('comment') }}</textarea>
                        @error('comment') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
            </tbody>
        </table>

        <p><input type="submit" value="Přidat rozhraní"></p>
    </form>
@endsection
