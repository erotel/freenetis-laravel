@extends('layouts.app')
@section('title', $setting ? 'Upravit pravidlo' : 'Přidat pravidlo')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('messages.index') }}">Zprávy</a> &raquo;
    <a href="{{ route('messages.show', $message->id) }}">{{ $message->name }}</a> &raquo;
    <a href="{{ route('message-auto-settings.show', $message->id) }}">Automatická aktivace</a> &raquo;
    {{ $setting ? 'Upravit pravidlo' : 'Přidat pravidlo' }}
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row">
    <h2>{{ $setting ? 'Upravit pravidlo pro automatickou aktivaci' : 'Přidat pravidlo pro automatickou aktivaci' }}</h2>
</div>
<div class="m-subtitle">Zpráva: {{ $message->name }}</div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

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

<div class="m-card" style="margin-bottom:16px;max-width:520px">
    <div class="m-form-group">
        <label class="m-form-label">Typ spouštění</label>
        <select class="m-form-select" name="type" id="typeSelect" onchange="updateAttrFields()">
            @foreach([1=>'měsíčně',2=>'týdně',3=>'denně',4=>'denně (prac. dny)',5=>'každou hodinu',6=>'v den stržení'] as $val => $label)
                <option value="{{ $val }}" {{ old('type', $setting?->type) == $val ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div id="rowDay" class="m-form-group" style="display:none">
        <label class="m-form-label" id="labelDay">Den</label>
        <input class="m-form-input" type="number" name="attr_day" value="{{ old('attr_day', $attrDay) }}" min="1" max="31" style="max-width:80px">
    </div>
    <div id="rowHour" class="m-form-group" style="display:none">
        <label class="m-form-label">Hodina (0–23)</label>
        <input class="m-form-input" type="number" name="attr_hour" value="{{ old('attr_hour', $attrHour) }}" min="0" max="23" style="max-width:80px">
    </div>
    <div style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:12px">
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
            <input type="checkbox" name="redirection_enabled" value="1"
                {{ old('redirection_enabled', $setting?->redirection_enabled) ? 'checked' : '' }}>
            Přesměrování
        </label>
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
            <input type="checkbox" name="email_enabled" value="1"
                {{ old('email_enabled', $setting?->email_enabled) ? 'checked' : '' }}>
            E-mail
        </label>
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
            <input type="checkbox" name="sms_enabled" value="1"
                {{ old('sms_enabled', $setting?->sms_enabled) ? 'checked' : '' }}>
            SMS
        </label>
    </div>
    <div class="m-form-group">
        <label class="m-form-label">Zaslat zprávu na e-mail</label>
        <input class="m-form-input" type="text" name="send_activation_to_email"
               value="{{ old('send_activation_to_email', $setting?->send_activation_to_email) }}"
               placeholder="admin@example.com">
        <div class="m-form-hint">Aktivační report se pošle na tuto adresu.</div>
    </div>
</div>

<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Uložit</button>
    <a class="m-btn" href="{{ route('message-auto-settings.show', $message->id) }}">Zrušit</a>
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
</div>
@endsection
