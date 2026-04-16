@extends('layouts.app')
@section('title', 'Přidat kontakt')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('users.index') }}">Uživatelé</a> &raquo;
    <a href="{{ route('users.show', $user->id) }}">{{ $user->full_name }}</a> &raquo;
    <a href="{{ route('contacts.show_by_user', $user->id) }}">Kontakty</a> &raquo;
    Přidat
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Přidat kontakt — {{ $user->full_name }}</h2></div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

<form method="POST" action="{{ route('contacts.store', $user->id) }}">
@csrf
<div class="m-card" style="margin-bottom:16px;max-width:420px">
    <div class="m-form-group">
        <label class="m-form-label" for="type">Typ <span style="color:#c0392b">*</span></label>
        <select class="m-form-select" id="type" name="type">
            <option value="">— vyberte typ —</option>
            @foreach($contactTypes as $id => $label)
                <option value="{{ $id }}" @selected(old('type') == $id)>{{ $label }}</option>
            @endforeach
        </select>
        @error('type') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    <div class="m-form-group">
        <label class="m-form-label" for="value">Hodnota <span style="color:#c0392b">*</span></label>
        <input class="m-form-input" type="text" id="value" name="value" value="{{ old('value') }}" maxlength="255">
        @error('value') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
</div>
<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Přidat</button>
    <a class="m-btn" href="{{ route('contacts.show_by_user', $user->id) }}">Zrušit</a>
</div>
</form>
</div>
@endsection
