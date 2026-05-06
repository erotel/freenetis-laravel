@extends('layouts.app')
@section('title', 'SMS zpráva #' . $sms->id)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('sms_messages.index') }}">SMS zprávy</a> &raquo; #{{ $sms->id }}
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>SMS zpráva #{{ $sms->id }}</h2></div>

<div class="m-actions">
    <a class="m-btn" href="{{ route('sms_messages.index') }}">&laquo; Zpět na seznam</a>
</div>

<div class="m-card" style="max-width:480px">
    <div class="m-card-title">Detail zprávy</div>
    <div class="m-field"><span class="m-field-label">ID</span><span class="m-field-value">{{ $sms->id }}</span></div>
    <div class="m-field"><span class="m-field-label">Typ</span><span class="m-field-value">{{ $sms->typeName() }}</span></div>
    <div class="m-field"><span class="m-field-label">Stav</span><span class="m-field-value">{{ $sms->stateName() }}</span></div>
    <div class="m-field"><span class="m-field-label">Odesílatel</span><span class="m-field-value" style="font-family:monospace">{{ $sms->sender }}</span></div>
    <div class="m-field"><span class="m-field-label">Příjemce</span><span class="m-field-value" style="font-family:monospace">{{ $sms->receiver }}</span></div>
    <div class="m-field">
        <span class="m-field-label">Uživatel</span>
        <span class="m-field-value">
            @if($sms->user) <a href="{{ route('users.show', $sms->user_id) }}">{{ $sms->user->login }}</a>
            @else — @endif
        </span>
    </div>
    <div class="m-field"><span class="m-field-label">Datum vytvoření</span><span class="m-field-value">{{ \Carbon\Carbon::parse($sms->stamp)->format('d.m.Y H:i:s') }}</span></div>
    <div class="m-field"><span class="m-field-label">Datum odeslání</span><span class="m-field-value">{{ \Carbon\Carbon::parse($sms->send_date)->format('d.m.Y H:i:s') }}</span></div>
    @if($sms->driver !== null)
    <div class="m-field"><span class="m-field-label">Driver</span><span class="m-field-value">{{ $sms->driver }}</span></div>
    @endif
    @if($sms->message)
    <div class="m-field"><span class="m-field-label">Chyba</span><span class="m-field-value" style="color:#c0392b">{{ $sms->message }}</span></div>
    @endif
    <div class="m-field" style="align-items:flex-start;flex-direction:column;gap:4px">
        <span class="m-field-label">Text</span>
        <div style="font-size:16px;background:#f7f7f5;border-radius:6px;padding:8px 10px;width:100%;white-space:pre-wrap">{{ $sms->text }}</div>
    </div>
</div>
</div>
@endsection
