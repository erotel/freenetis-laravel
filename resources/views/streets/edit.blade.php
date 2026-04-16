@extends('layouts.app')
@section('title', 'Upravit ulici')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('streets.index') }}">Ulice</a> &raquo;
    {{ $street->street }} &raquo; Upravit
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Upravit ulici: {{ $street->street }}</h2></div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

<form method="POST" action="{{ route('streets.update', $street->id) }}">
@csrf
@method('PUT')
<div class="m-card" style="margin-bottom:16px;max-width:420px">
    <div class="m-form-group">
        <label class="m-form-label" for="town_id">Město <span style="color:#c0392b">*</span></label>
        <select class="m-form-select" id="town_id" name="town_id">
            <option value="">— vyberte město —</option>
            @foreach($towns as $town)
                <option value="{{ $town->id }}" @selected(old('town_id', $street->town_id) == $town->id)>
                    {{ $town->town }}{{ $town->quarter ? ' - ' . $town->quarter : '' }}, {{ $town->zip_code }}
                </option>
            @endforeach
        </select>
        @error('town_id') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    <div class="m-form-group">
        <label class="m-form-label" for="street">Ulice <span style="color:#c0392b">*</span></label>
        <input class="m-form-input" type="text" id="street" name="street" value="{{ old('street', $street->street) }}" maxlength="50">
        @error('street') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
</div>
<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Uložit</button>
    <a class="m-btn" href="{{ route('streets.show', $street->id) }}">Zrušit</a>
</div>
</form>
</div>
@endsection
