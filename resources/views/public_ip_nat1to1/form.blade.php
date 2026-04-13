@extends('layouts.app')

@section('title', $action === 'create' ? 'Přidat veřejnou IP (1:1 NAT)' : 'Upravit veřejnou IP (1:1 NAT)')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('public-ip-nat.index') }}">Veřejné IP (1:1 NAT)</a> &raquo;
        {{ $action === 'create' ? 'Přidat' : 'Upravit' }}
    </div>
@endsection

@section('content')
    <h2>{{ $action === 'create' ? 'Přidat veřejnou IP (1:1 NAT)' : 'Upravit veřejnou IP (1:1 NAT)' }}</h2>

    @if($action === 'create')
        <form method="POST" action="{{ route('public-ip-nat.store') }}" class="form">
    @else
        <form method="POST" action="{{ route('public-ip-nat.update', $record->id) }}" class="form">
        @method('PUT')
    @endif
        @csrf

        <table class="extended" cellspacing="0">
            <tbody>
                <tr>
                    <th><label for="public_ip">Veřejná IP <span class="required">*</span></label></th>
                    <td>
                        @if($action === 'create')
                            <input type="text" id="public_ip" name="public_ip"
                                   value="{{ old('public_ip') }}" maxlength="45">
                            @error('public_ip') <span class="error">{{ $message }}</span> @enderror
                        @else
                            <strong>{{ $record->public_ip }}</strong>
                        @endif
                    </td>
                </tr>
                <tr>
                    <th><label for="private_ip">Privátní IP</label></th>
                    <td>
                        <input type="text" id="private_ip" name="private_ip"
                               value="{{ old('private_ip', $record?->private_ip) }}" maxlength="45"
                               placeholder="prázdné = bez mapování">
                        @error('private_ip') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th>Aktivní</th>
                    <td>
                        @php
                            $checkedEnabled = old('enabled') !== null
                                ? (bool) old('enabled')
                                : ($record ? (bool) $record->enabled : true);
                        @endphp
                        <input type="checkbox" id="enabled" name="enabled" value="1"
                               @checked($checkedEnabled)>
                        <label for="enabled">Aktivní</label>
                    </td>
                </tr>
            </tbody>
        </table>

        <p>
            <button type="submit">Uložit</button>
            <a href="{{ route('public-ip-nat.index') }}">Zrušit</a>
        </p>
    </form>
@endsection
