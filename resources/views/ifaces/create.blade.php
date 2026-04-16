@extends('layouts.app')
@section('title', 'Přidat rozhraní')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('devices.index') }}">Zařízení</a> &raquo;
    @if($device) <a href="{{ route('devices.show', $device->id) }}">{{ $device->name }}</a> &raquo; @endif
    Přidat rozhraní
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Přidat rozhraní</h2></div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

<form method="POST" action="{{ route('ifaces.store') }}">
@csrf
<div class="m-card" style="margin-bottom:16px;max-width:480px">
    <div class="m-form-group">
        <label class="m-form-label">Zařízení</label>
        @if($device)
            <div class="m-form-input" style="background:#f7f7f5;cursor:default"><strong>{{ $device->name }}</strong></div>
            <input type="hidden" name="device_id" value="{{ $device->id }}">
        @else
            <input class="m-form-input" type="number" name="device_id" value="{{ old('device_id') }}" placeholder="ID zařízení">
        @endif
        @error('device_id') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="type">Typ <span style="color:#c0392b">*</span></label>
            <select class="m-form-select" id="type" name="type">
                @foreach($types as $value => $label)
                    <option value="{{ $value }}" @selected(old('type', 1) == $value)>{{ $label }}</option>
                @endforeach
            </select>
            @error('type') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="name">Název <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="text" id="name" name="name"
                   value="{{ old('name') }}" maxlength="254" placeholder="eth0, wlan0, …">
            @error('name') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="m-form-group">
        <label class="m-form-label" for="mac">MAC adresa</label>
        <input class="m-form-input" type="text" id="mac" name="mac"
               value="{{ old('mac') }}" maxlength="20" placeholder="aa:bb:cc:dd:ee:ff">
        <div class="m-form-hint">Povinné pro Ethernet a Wireless</div>
        @error('mac') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    <div class="m-form-group">
        <label class="m-form-label" for="comment">Komentář</label>
        <textarea class="m-form-input" id="comment" name="comment" rows="3" maxlength="254">{{ old('comment') }}</textarea>
        @error('comment') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
</div>
<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Přidat rozhraní</button>
    @if($device)
    <a class="m-btn" href="{{ route('devices.show', $device->id) }}">Zrušit</a>
    @else
    <a class="m-btn" href="{{ route('devices.index') }}">Zrušit</a>
    @endif
</div>
</form>
</div>
@endsection
