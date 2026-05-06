@extends('layouts.app')
@section('title', $action === 'create' ? 'Přidat veřejnou IP (1:1 NAT)' : 'Upravit veřejnou IP (1:1 NAT)')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('public-ip-nat.index') }}">Veřejné IP (1:1 NAT)</a> &raquo;
    {{ $action === 'create' ? 'Přidat' : 'Upravit' }}
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row">
    <h2>{{ $action === 'create' ? 'Přidat veřejnou IP (1:1 NAT)' : 'Upravit veřejnou IP (1:1 NAT)' }}</h2>
</div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

@if($action === 'create')
<form method="POST" action="{{ route('public-ip-nat.store') }}">
@else
<form method="POST" action="{{ route('public-ip-nat.update', $record->id) }}">
@method('PUT')
@endif
@csrf

<div class="m-card" style="margin-bottom:16px;max-width:420px">
    <div class="m-form-group">
        <label class="m-form-label" for="public_ip">Veřejná IP <span style="color:#c0392b">*</span></label>
        @if($action === 'create')
            <input class="m-form-input" type="text" id="public_ip" name="public_ip"
                   value="{{ old('public_ip') }}" maxlength="45" style="font-family:monospace">
            @error('public_ip') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        @else
            <div class="m-form-input" style="background:#f7f7f5;cursor:default;font-family:monospace">{{ $record->public_ip }}</div>
        @endif
    </div>
    <div class="m-form-group">
        <label class="m-form-label" for="private_ip">Privátní IP</label>
        <input class="m-form-input" type="text" id="private_ip" name="private_ip"
               value="{{ old('private_ip', $record?->private_ip) }}" maxlength="45"
               placeholder="prázdné = bez mapování" style="font-family:monospace">
        @error('private_ip') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    <div class="m-form-group">
        @php
            $checkedEnabled = old('enabled') !== null
                ? (bool) old('enabled')
                : ($record ? (bool) $record->enabled : true);
        @endphp
        <label style="display:flex;align-items:center;gap:6px;font-size:16px;cursor:pointer">
            <input type="checkbox" id="enabled" name="enabled" value="1" @checked($checkedEnabled)>
            Aktivní
        </label>
    </div>
</div>

<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Uložit</button>
    <a class="m-btn" href="{{ route('public-ip-nat.index') }}">Zrušit</a>
</div>
</form>
</div>
@endsection
