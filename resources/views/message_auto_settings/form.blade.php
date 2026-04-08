@extends('layouts.app')
@section('title', $setting ? 'Upravit pravidlo' : 'Přidat pravidlo')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('messages.index') }}">Zprávy</a> »
        <a href="{{ route('messages.show', $message->id) }}">{{ $message->name }}</a> »
        <a href="{{ route('message-auto-settings.show', $message->id) }}">Automatická aktivace</a> »
        {{ $setting ? 'Upravit pravidlo' : 'Přidat pravidlo' }}
    </div>
@endsection
@section('content')
    <h2>{{ $setting ? 'Upravit pravidlo pro automatickou aktivaci' : 'Přidat pravidlo pro automatickou aktivaci' }}</h2>
    <p><strong>Zpráva:</strong> {{ $message->name }}</p>

    @php
        $action = $setting
            ? route('message-auto-settings.update', $setting->id)
            : route('message-auto-settings.store', $message->id);
        $method = $setting ? 'PUT' : 'POST';

        $attr     = $setting?->attribute ?? '';
        $attrParts = explode('/', $attr);
        $attrDay  = $attrParts[0] ?? '';
        $attrHour = $attrParts[1] ?? '';
    @endphp

    <form method="POST" action="{{ $action }}" id="autoSettingForm">
        @csrf
        @if($method === 'PUT') @method('PUT') @endif

        <table class="extended" cellspacing="0">
            <tr>
                <th>Typ spouštění</th>
                <td>
                    <select name="type" id="typeSelect" onchange="updateAttrFields()">
                        @foreach([1=>'měsíčně',2=>'týdně',3=>'denně',4=>'denně (prac. dny)',5=>'každou hodinu',6=>'v den stržení'] as $val => $label)
                            <option value="{{ $val }}" {{ old('type', $setting?->type) == $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </td>
            </tr>
            <tr id="rowDay" style="display:none">
                <th id="labelDay">Den</th>
                <td><input type="number" name="attr_day" value="{{ old('attr_day', $attrDay) }}" min="1" max="31" style="width:60px"></td>
            </tr>
            <tr id="rowHour" style="display:none">
                <th>Hodina (0–23)</th>
                <td><input type="number" name="attr_hour" value="{{ old('attr_hour', $attrHour) }}" min="0" max="23" style="width:60px"></td>
            </tr>
            <tr>
                <th>Přesměrování</th>
                <td><input type="checkbox" name="redirection_enabled" value="1" {{ old('redirection_enabled', $setting?->redirection_enabled) ? 'checked' : '' }}></td>
            </tr>
            <tr>
                <th>E-mail</th>
                <td><input type="checkbox" name="email_enabled" value="1" {{ old('email_enabled', $setting?->email_enabled) ? 'checked' : '' }}></td>
            </tr>
            <tr>
                <th>SMS</th>
                <td><input type="checkbox" name="sms_enabled" value="1" {{ old('sms_enabled', $setting?->sms_enabled) ? 'checked' : '' }}></td>
            </tr>
            <tr>
                <th>Zaslat zprávu na e-mail</th>
                <td>
                    <input type="text" name="send_activation_to_email"
                           value="{{ old('send_activation_to_email', $setting?->send_activation_to_email) }}"
                           style="width:250px" placeholder="admin@example.com">
                    <small style="color:#888;">Aktivační report se pošle na tuto adresu.</small>
                </td>
            </tr>
        </table>

        <div style="margin-top:1em;">
            <button type="submit">Uložit</button>
            <a href="{{ route('message-auto-settings.show', $message->id) }}" style="margin-left:1em;">Zrušit</a>
        </div>
    </form>

    <script>
    function updateAttrFields() {
        const type     = parseInt(document.getElementById('typeSelect').value);
        const rowDay   = document.getElementById('rowDay');
        const rowHour  = document.getElementById('rowHour');
        const labelDay = document.getElementById('labelDay');

        rowDay.style.display  = [1, 2, 6].includes(type) ? '' : 'none';
        rowHour.style.display = [1, 2, 3, 4, 6].includes(type) ? '' : 'none';

        labelDay.textContent = type === 2 ? 'Den týdne (1=Po, 7=Ne)' : 'Den v měsíci (1–31)';
    }
    updateAttrFields();
    </script>
@endsection
