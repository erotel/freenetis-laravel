@extends('layouts.app')
@section('title', 'Přidat skupinu')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('aro-groups.index') }}">Přístupová práva</a> &raquo; Přidat skupinu
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Přidat skupinu přístupových práv</h2></div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

<form method="POST" action="{{ route('aro-groups.store') }}">
@csrf
<div class="m-card" style="margin-bottom:16px;max-width:420px">
    <div class="m-form-group">
        <label class="m-form-label">Název skupiny</label>
        <input class="m-form-input" type="text" name="name" value="{{ old('name') }}">
        @error('name') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    <div class="m-form-group">
        <label class="m-form-label">Nadřazená skupina</label>
        <select class="m-form-select" name="parent_id">
            @foreach($groups as $g)
                <option value="{{ $g->id }}" {{ old('parent_id') == $g->id ? 'selected' : '' }}>
                    {{ $g->name }}
                </option>
            @endforeach
        </select>
        @error('parent_id') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
</div>
<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Uložit</button>
    <a class="m-btn" href="{{ route('aro-groups.index') }}">Zrušit</a>
</div>
</form>
</div>
@endsection
