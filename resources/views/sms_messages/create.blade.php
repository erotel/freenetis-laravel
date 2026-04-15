@extends('layouts.app')

@section('title', 'Odeslat SMS')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('sms_messages.index') }}">SMS zprávy</a> &raquo;
        Odeslat SMS
    </div>
@endsection

@section('content')
<h2>Odeslat SMS</h2>

@if($errors->any())
    <div style="color:#c00; margin-bottom:1em;">
        <ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

<form method="POST" action="{{ route('sms_messages.store') }}">
    @csrf
    <table class="extended" cellspacing="0">
        <tbody>
            <tr>
                <th>Odesílatel</th>
                <td>{{ $senderNumber }}</td>
            </tr>
            <tr>
                <th>Příjemce <span style="color:#c00;">*</span></th>
                <td>
                    <input type="text" name="receiver" value="{{ old('receiver') }}"
                           minlength="9" maxlength="13" required
                           placeholder="420777123456" style="width:180px;">
                    <br><small style="color:#888;">9–13 číslic, bez mezer</small>
                </td>
            </tr>
            <tr>
                <th>Datum odeslání <span style="color:#c00;">*</span></th>
                <td>
                    <input type="datetime-local" name="send_date"
                           value="{{ old('send_date', now()->format('Y-m-d\TH:i')) }}"
                           required>
                </td>
            </tr>
            <tr>
                <th>Text <span style="color:#c00;">*</span></th>
                <td>
                    <textarea name="text" maxlength="760" required
                              style="width:530px; height:150px;">{{ old('text') }}</textarea>
                    <br><small style="color:#888;">Max. 760 znaků. Text bude převeden do ASCII.</small>
                </td>
            </tr>
        </tbody>
    </table>

    <div style="margin-top:1em;">
        <button type="submit">Odeslat SMS</button>
        &nbsp;
        <a href="{{ route('sms_messages.index') }}">Zrušit</a>
    </div>
</form>
@endsection
