@extends('layouts.app')
@section('title', $action === 'create' ? 'Přidat port forward' : 'Upravit port forward')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('public-port-forwards.index') }}">Veřejné porty</a> &raquo;
    {{ $action === 'create' ? 'Přidat' : 'Upravit' }}
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>{{ $action === 'create' ? 'Přidat port forward' : 'Upravit port forward' }}</h2></div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

@if($action === 'create')
<form method="POST" action="{{ route('public-port-forwards.store') }}">
@else
<form method="POST" action="{{ route('public-port-forwards.update', $record->id) }}">
@method('PUT')
@endif
@csrf

<div class="m-card" style="margin-bottom:16px;max-width:520px">
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="public_ip">Veřejná IP <span style="color:#c0392b">*</span></label>
            <select class="m-form-select" id="public_ip" name="public_ip">
                <option value="">— vyberte —</option>
                @foreach($publicIps as $ip)
                    <option value="{{ $ip }}" @selected(old('public_ip', $record?->public_ip) === $ip)>{{ $ip }}</option>
                @endforeach
            </select>
            @error('public_ip') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="protocol">Protokol <span style="color:#c0392b">*</span></label>
            <select class="m-form-select" id="protocol" name="protocol" style="max-width:100px">
                <option value="tcp" @selected(old('protocol', $record?->protocol ?? 'tcp') === 'tcp')>TCP</option>
                <option value="udp" @selected(old('protocol', $record?->protocol) === 'udp')>UDP</option>
            </select>
            @error('protocol') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="public_port_from">Veřejný port od <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="number" id="public_port_from" name="public_port_from"
                   value="{{ old('public_port_from', $record?->public_port_from) }}"
                   min="1" max="65535" style="max-width:100px;font-family:monospace">
            @error('public_port_from') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="public_port_to">Veřejný port do</label>
            <input class="m-form-input" type="number" id="public_port_to" name="public_port_to"
                   value="{{ old('public_port_to', $record?->public_port_to) }}"
                   min="1" max="65535" style="max-width:100px;font-family:monospace">
            <div class="m-form-hint">prázdné = stejný jako „od"</div>
            @error('public_port_to') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="private_ip">Privátní IP <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="text" id="private_ip" name="private_ip"
                   value="{{ old('private_ip', $record?->private_ip) }}" maxlength="45" style="font-family:monospace">
            @error('private_ip') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div></div>
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="private_port_from">Privátní port od <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="number" id="private_port_from" name="private_port_from"
                   value="{{ old('private_port_from', $record?->private_port_from) }}"
                   min="1" max="65535" style="max-width:100px;font-family:monospace">
            @error('private_port_from') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="private_port_to">Privátní port do</label>
            <input class="m-form-input" type="number" id="private_port_to" name="private_port_to"
                   value="{{ old('private_port_to', $record?->private_port_to) }}"
                   min="1" max="65535" style="max-width:100px;font-family:monospace">
            <div class="m-form-hint">prázdné = stejný jako „od"</div>
            @error('private_port_to') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="m-form-group">
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
            <input type="checkbox" id="enabled" name="enabled" value="1"
                   @checked(old('enabled', $record ? $record->enabled : true))>
            Aktivní
        </label>
    </div>
</div>

<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Uložit</button>
    <a class="m-btn" href="{{ route('public-port-forwards.index') }}">Zrušit</a>
</div>
</form>
</div>
@endsection
