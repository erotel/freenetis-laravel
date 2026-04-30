@extends('layouts.app')
@section('title', $action === 'create' ? 'Přidat SMTP výjimku' : 'Upravit SMTP výjimku')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('smtp-exceptions.index') }}">SMTP výjimky</a> &raquo;
    {{ $action === 'create' ? 'Přidat' : 'Upravit' }}
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>{{ $action === 'create' ? 'Přidat SMTP výjimku' : 'Upravit SMTP výjimku' }}</h2></div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

@if($action === 'create')
<form method="POST" action="{{ route('smtp-exceptions.store') }}">
@else
<form method="POST" action="{{ route('smtp-exceptions.update', $record->id) }}">
@method('PUT')
@endif
@csrf

<div class="m-card" style="margin-bottom:16px;max-width:520px">
    <div class="m-form-group">
        <label class="m-form-label" for="intip">IP adresa <span style="color:#c0392b">*</span></label>
        <input class="m-form-input" type="text" id="intip" name="intip"
               value="{{ old('intip', $record?->intip) }}" maxlength="45" style="font-family:monospace;max-width:200px"
               placeholder="10.0.0.1">
        <div class="m-form-hint">Vnitřní IPv4 adresa, pro kterou bude na IGW povolen port 25.</div>
        @error('intip') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>

    @if($record)
    <div class="m-form-group" style="margin-top:18px">
        <div class="m-form-hint">
            Přidal: <strong>{{ $record->user }}</strong>,
            datum: <strong>{{ $record->datum?->format('d.m.Y') ?? '—' }}</strong>
            (auditní údaje, neměníme je při editaci)
        </div>
    </div>
    @endif
</div>

<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Uložit</button>
    <a class="m-btn" href="{{ route('smtp-exceptions.index') }}">Zrušit</a>
</div>
</form>
</div>
@endsection
