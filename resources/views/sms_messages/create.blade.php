@extends('layouts.app')
@section('title', 'Odeslat SMS')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('sms_messages.index') }}">SMS zprávy</a> &raquo; Odeslat SMS
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Odeslat SMS</h2></div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

<form method="POST" action="{{ route('sms_messages.store') }}">
@csrf
<div class="m-card" style="margin-bottom:16px;max-width:520px">
    <div class="m-field" style="margin-bottom:8px">
        <span class="m-field-label">Odesílatel</span>
        <span class="m-field-value" style="font-family:monospace">{{ $senderNumber }}</span>
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label">Příjemce <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="text" name="receiver" value="{{ old('receiver') }}"
                   minlength="9" maxlength="13" required placeholder="420777123456" style="font-family:monospace">
            <div class="m-form-hint">9–13 číslic, bez mezer</div>
        </div>
        <div class="m-form-group">
            <label class="m-form-label">Datum odeslání <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="datetime-local" name="send_date"
                   value="{{ old('send_date', now()->format('Y-m-d\TH:i')) }}" required>
        </div>
    </div>
    <div class="m-form-group">
        <label class="m-form-label">Text <span style="color:#c0392b">*</span></label>
        <textarea class="m-form-input" name="text" maxlength="760" required rows="5">{{ old('text') }}</textarea>
        <div class="m-form-hint">Max. 760 znaků. Text bude převeden do ASCII.</div>
    </div>
</div>
<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Odeslat SMS</button>
    <a class="m-btn" href="{{ route('sms_messages.index') }}">Zrušit</a>
</div>
</form>
</div>
@endsection
