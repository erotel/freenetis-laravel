@extends('layouts.app')

@section('title', 'SMS zpráva #' . $sms->id)

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('sms_messages.index') }}">SMS zprávy</a> &raquo;
        #{{ $sms->id }}
    </div>
@endsection

@section('content')
<h2>SMS zpráva #{{ $sms->id }}</h2>

<table class="extended" cellspacing="0">
    <thead>
        <tr><th colspan="2">Detail zprávy</th></tr>
    </thead>
    <tbody>
        <tr>
            <th>ID</th>
            <td>{{ $sms->id }}</td>
        </tr>
        <tr>
            <th>Typ</th>
            <td>{{ $sms->typeName() }}</td>
        </tr>
        <tr>
            <th>Stav</th>
            <td>{{ $sms->stateName() }}</td>
        </tr>
        <tr>
            <th>Odesílatel</th>
            <td>{{ $sms->sender }}</td>
        </tr>
        <tr>
            <th>Příjemce</th>
            <td>{{ $sms->receiver }}</td>
        </tr>
        <tr>
            <th>Uživatel</th>
            <td>
                @if($sms->user)
                    <a href="{{ route('users.show', $sms->user_id) }}">{{ $sms->user->login }}</a>
                @else
                    —
                @endif
            </td>
        </tr>
        <tr>
            <th>Datum vytvoření</th>
            <td>{{ \Carbon\Carbon::parse($sms->stamp)->format('d.m.Y H:i:s') }}</td>
        </tr>
        <tr>
            <th>Datum odeslání</th>
            <td>{{ \Carbon\Carbon::parse($sms->send_date)->format('d.m.Y H:i:s') }}</td>
        </tr>
        @if($sms->driver !== null)
        <tr>
            <th>Driver</th>
            <td>{{ $sms->driver }}</td>
        </tr>
        @endif
        @if($sms->message)
        <tr>
            <th>Zpráva (chyba)</th>
            <td style="color:#c00;">{{ $sms->message }}</td>
        </tr>
        @endif
        <tr>
            <th>Text</th>
            <td style="white-space:pre-wrap;">{{ $sms->text }}</td>
        </tr>
    </tbody>
</table>

<div style="margin-top:1em;">
    <a href="{{ route('sms_messages.index') }}">&laquo; Zpět na seznam</a>
</div>
@endsection
