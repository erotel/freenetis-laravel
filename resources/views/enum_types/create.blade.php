@extends('layouts.app')
@section('title', 'Přidat typ')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs"><a href="{{ route('enum-types.index') }}">Typy</a> &raquo; Přidat typ</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Přidat typ</h2></div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

<form method="POST" action="{{ route('enum-types.store') }}">
@csrf
<div class="m-card" style="margin-bottom:16px;max-width:420px">
    <div class="m-form-group">
        <label class="m-form-label">Skupina</label>
        <select class="m-form-select" name="type_id">
            @foreach($typeNames as $tn)
                <option value="{{ $tn->id }}" {{ old('type_id') == $tn->id ? 'selected' : '' }}>
                    {{ $tn->type_name }}
                </option>
            @endforeach
        </select>
        @error('type_id') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    <div class="m-form-group">
        <label class="m-form-label">Název</label>
        <input class="m-form-input" type="text" name="value" value="{{ old('value') }}">
        @error('value') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
</div>
<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Uložit</button>
    <a class="m-btn" href="{{ route('enum-types.index') }}">Zrušit</a>
</div>
</form>
</div>
@endsection
