@extends('layouts.app')
@section('title', 'Přidat město')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('towns.index') }}">Města</a> &raquo; Přidat nové město
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Přidat nové město</h2></div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

<form method="POST" action="{{ route('towns.store') }}">
@csrf
<div class="m-card" style="margin-bottom:16px;max-width:420px">
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="town">Město <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="text" id="town" name="town" value="{{ old('town') }}" maxlength="50">
            @error('town') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="zip_code">PSČ <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="text" id="zip_code" name="zip_code" value="{{ old('zip_code') }}" maxlength="5" style="max-width:90px">
            @error('zip_code') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="m-form-group">
        <label class="m-form-label" for="quarter">Čtvrť</label>
        <input class="m-form-input" type="text" id="quarter" name="quarter" value="{{ old('quarter') }}" maxlength="50">
        @error('quarter') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
</div>
<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Přidat</button>
    <a class="m-btn" href="{{ route('towns.index') }}">Zrušit</a>
</div>
</form>
</div>
@endsection
