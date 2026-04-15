@extends('layouts.app')

@section('title', 'Nová žádost o připojení')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('connection_requests.index') }}">Žádosti o připojení</a> &raquo;
        Nová žádost
    </div>
@endsection

@section('content')
<h2>Nová žádost o připojení</h2>

@if($errors->any())
    <div style="color:#c00; margin-bottom:1em;">
        <ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

<form method="POST" action="{{ route('connection_requests.store') }}">
    @csrf
    <input type="hidden" name="subnet_id" value="{{ $subnet->id }}">

    <table class="extended" cellspacing="0">
        <tbody>
            @if($canEditDevices)
            <tr>
                <th>Vlastník připojení <span style="color:#c00;">*</span></th>
                <td>
                    <select name="member_id" required style="width:250px;">
                        <option value="">— vyberte člena —</option>
                        @foreach($members as $id => $name)
                            <option value="{{ $id }}" @selected(old('member_id', $authMemberId) == $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                </td>
            </tr>
            @endif
            <tr>
                <th>Subnet</th>
                <td>{{ $subnet->name }} ({{ $subnet->network_address }}/{{ $subnet->netmask }})</td>
            </tr>
            <tr>
                <th>IP adresa</th>
                <td>
                    <strong>{{ $ipAddress }}</strong>
                    <input type="hidden" name="ip_address" value="{{ $ipAddress }}">
                </td>
            </tr>
            <tr>
                <th>MAC adresa <span style="color:#c00;">*</span></th>
                <td>
                    <input type="text" name="mac_address" value="{{ old('mac_address') }}"
                           required style="width:150px;" placeholder="AA:BB:CC:DD:EE:FF">
                    <br><small style="color:#888;">Formát: AA:BB:CC:DD:EE:FF</small>
                </td>
            </tr>
            <tr>
                <th>Typ zařízení <span style="color:#c00;">*</span></th>
                <td>
                    <select name="device_type_id" required style="width:200px;">
                        <option value="">— vyberte typ —</option>
                        @foreach($deviceTypes as $id => $label)
                            <option value="{{ $id }}" @selected(old('device_type_id', $defaultType) == $id)>{{ $label }}</option>
                        @endforeach
                    </select>
                </td>
            </tr>
            @if($canEditDevices)
            <tr>
                <th>Šablona zařízení</th>
                <td>
                    <select name="device_template_id" style="width:200px;">
                        <option value="">— bez šablony —</option>
                        @foreach($templates as $id => $name)
                            <option value="{{ $id }}" @selected(old('device_template_id') == $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                </td>
            </tr>
            @endif
            <tr>
                <th>Komentář</th>
                <td>
                    <textarea name="comment" style="width:350px; height:80px;">{{ old('comment') }}</textarea>
                </td>
            </tr>
        </tbody>
    </table>

    <div style="margin-top:1em;">
        <button type="submit">Odeslat žádost</button>
        &nbsp;
        <a href="{{ route('connection_requests.index') }}">Zrušit</a>
    </div>
</form>
@endsection
