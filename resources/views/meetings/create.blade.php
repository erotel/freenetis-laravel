@extends('layouts.app')
@section('title', 'Nová schůze')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('meetings.index') }}">Schůze členů</a> &raquo; Nová
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Nová schůze</h2></div>

<div class="m-card" style="max-width:520px">
<form method="POST" action="{{ route('meetings.store') }}">
    @csrf
    <div class="m-form-group">
        <label class="m-form-label" for="title">Název</label>
        <input class="m-form-input" id="title" name="title" value="{{ old('title') }}" maxlength="255" required>
        @error('title')<div class="m-form-hint" style="color:#c0392b">{{ $message }}</div>@enderror
    </div>
    <div class="m-form-group">
        <label class="m-form-label" for="held_on">Datum konání</label>
        <input class="m-form-input" type="date" id="held_on" name="held_on" value="{{ old('held_on', date('Y-m-d')) }}" required>
        <div class="m-form-hint">U zpětného zápisu minulých schůzí zadej jejich skutečné datum.</div>
        @error('held_on')<div class="m-form-hint" style="color:#c0392b">{{ $message }}</div>@enderror
    </div>
    <div class="m-form-group">
        <label class="m-form-label" for="type">Typ</label>
        <select class="m-form-select" id="type" name="type">
            @foreach($typeLabels as $val => $lbl)
            <option value="{{ $val }}" @selected(old('type','clenska_schuze')===$val)>{{ $lbl }}</option>
            @endforeach
        </select>
    </div>
    <div class="m-form-group">
        <label class="m-form-label" for="quorum_percent">Práh usnášeníschopnosti (%)</label>
        <input class="m-form-input" type="number" id="quorum_percent" name="quorum_percent"
               value="{{ old('quorum_percent', 50) }}" min="1" max="100" style="width:100px">
        <div class="m-form-hint">Kolik % seniorů (přítomných + plných mocí) je potřeba pro usnášeníschopnost.</div>
        @error('quorum_percent')<div class="m-form-hint" style="color:#c0392b">{{ $message }}</div>@enderror
    </div>
    <div class="m-form-group">
        <label class="m-form-label" for="comment">Poznámka</label>
        <input class="m-form-input" id="comment" name="comment" value="{{ old('comment') }}" maxlength="500">
    </div>
    <div style="display:flex;gap:8px;margin-top:6px">
        <button class="m-btn m-btn-primary" type="submit">Založit</button>
        <a class="m-btn" href="{{ route('meetings.index') }}">Zpět</a>
    </div>
</form>
</div>
</div>
@endsection
