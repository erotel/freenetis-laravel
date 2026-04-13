@extends('layouts.app')

@section('title', $action === 'create' ? 'Přidat port forward' : 'Upravit port forward')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('public-port-forwards.index') }}">Veřejné porty</a> &raquo;
        {{ $action === 'create' ? 'Přidat' : 'Upravit' }}
    </div>
@endsection

@section('content')
    <h2>{{ $action === 'create' ? 'Přidat port forward' : 'Upravit port forward' }}</h2>

    @if($action === 'create')
        <form method="POST" action="{{ route('public-port-forwards.store') }}" class="form">
    @else
        <form method="POST" action="{{ route('public-port-forwards.update', $record->id) }}" class="form">
        @method('PUT')
    @endif
        @csrf

        <table class="extended" cellspacing="0">
            <tbody>
                <tr>
                    <th><label for="public_ip">Veřejná IP <span class="required">*</span></label></th>
                    <td>
                        <select id="public_ip" name="public_ip">
                            <option value="">— vyberte —</option>
                            @foreach($publicIps as $ip)
                                <option value="{{ $ip }}"
                                    @selected(old('public_ip', $record?->public_ip) === $ip)>
                                    {{ $ip }}
                                </option>
                            @endforeach
                        </select>
                        @error('public_ip') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="protocol">Protokol <span class="required">*</span></label></th>
                    <td>
                        <select id="protocol" name="protocol">
                            <option value="tcp" @selected(old('protocol', $record?->protocol ?? 'tcp') === 'tcp')>TCP</option>
                            <option value="udp" @selected(old('protocol', $record?->protocol) === 'udp')>UDP</option>
                        </select>
                        @error('protocol') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="public_port_from">Veřejný port od <span class="required">*</span></label></th>
                    <td>
                        <input type="number" id="public_port_from" name="public_port_from"
                               value="{{ old('public_port_from', $record?->public_port_from) }}"
                               min="1" max="65535" style="width:7em;">
                        @error('public_port_from') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="public_port_to">Veřejný port do</label></th>
                    <td>
                        <input type="number" id="public_port_to" name="public_port_to"
                               value="{{ old('public_port_to', $record?->public_port_to) }}"
                               min="1" max="65535" style="width:7em;">
                        <small>(prázdné = stejný jako „od")</small>
                        @error('public_port_to') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="private_ip">Privátní IP <span class="required">*</span></label></th>
                    <td>
                        <input type="text" id="private_ip" name="private_ip"
                               value="{{ old('private_ip', $record?->private_ip) }}" maxlength="45">
                        @error('private_ip') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="private_port_from">Privátní port od <span class="required">*</span></label></th>
                    <td>
                        <input type="number" id="private_port_from" name="private_port_from"
                               value="{{ old('private_port_from', $record?->private_port_from) }}"
                               min="1" max="65535" style="width:7em;">
                        @error('private_port_from') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="private_port_to">Privátní port do</label></th>
                    <td>
                        <input type="number" id="private_port_to" name="private_port_to"
                               value="{{ old('private_port_to', $record?->private_port_to) }}"
                               min="1" max="65535" style="width:7em;">
                        <small>(prázdné = stejný jako „od")</small>
                        @error('private_port_to') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th>Aktivní</th>
                    <td>
                        <input type="checkbox" id="enabled" name="enabled" value="1"
                               @checked(old('enabled', $record ? $record->enabled : true))>
                        <label for="enabled">Aktivní</label>
                    </td>
                </tr>
            </tbody>
        </table>

        <p>
            <button type="submit">Uložit</button>
            <a href="{{ route('public-port-forwards.index') }}">Zrušit</a>
        </p>
    </form>
@endsection
