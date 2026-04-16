@extends('layouts.app')
@section('title', 'Upravit město')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('towns.index') }}">Města</a> &raquo; Upravit město
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Upravit město: {{ $town->town }}</h2></div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

<form method="POST" action="{{ route('towns.update', $town->id) }}">
@csrf
@method('PUT')
<div class="m-card" style="margin-bottom:16px;max-width:420px">
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="town">Město <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="text" id="town" name="town" value="{{ old('town', $town->town) }}" maxlength="50">
            @error('town') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="zip_code">PSČ <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="text" id="zip_code" name="zip_code" value="{{ old('zip_code', $town->zip_code) }}" maxlength="5" style="max-width:90px">
            @error('zip_code') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="m-form-group">
        <label class="m-form-label" for="quarter">Čtvrť</label>
        <input class="m-form-input" type="text" id="quarter" name="quarter" value="{{ old('quarter', $town->quarter) }}" maxlength="50">
        @error('quarter') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
</div>
<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Uložit</button>
    <a class="m-btn" href="{{ route('towns.show', $town->id) }}">Zrušit</a>
</div>
</form>
</div>
@endsection
