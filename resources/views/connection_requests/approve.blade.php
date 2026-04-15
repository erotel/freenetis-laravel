@extends('layouts.app')

@section('title', 'Schválit žádost #' . $cr->id)

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('connection_requests.index') }}">Žádosti o připojení</a> &raquo;
        <a href="{{ route('connection_requests.show', $cr->id) }}">#{{ $cr->id }}</a> &raquo;
        Schválit
    </div>
@endsection

@section('content')
<h2>Schválit žádost #{{ $cr->id }}</h2>

<p>IP: <strong>{{ $cr->ip_address }}</strong>, MAC: <strong>{{ $cr->mac_address }}</strong></p>

<form method="POST" action="{{ route('connection_requests.approve', $cr->id) }}">
    @csrf

    <table class="extended" cellspacing="0">
        <tbody>
            <tr>
                <th>Šablona zařízení <span style="color:#c00;">*</span></th>
                <td>
                    <select name="device_template_id" required style="width:220px;">
                        <option value="">— vyberte šablonu —</option>
                        @foreach($templates as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </td>
            </tr>
        </tbody>
    </table>

    <div style="margin-top:1em;">
        <button type="submit">Pokračovat</button>
        &nbsp;
        <a href="{{ route('connection_requests.show', $cr->id) }}">Zrušit</a>
    </div>
</form>
@endsection
