@extends('layouts.app')
@section('title', 'Upravit skupinu')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('aro-groups.index') }}">Přístupová práva</a> &raquo;
    <a href="{{ route('aro-groups.show', $group->id) }}">{{ $group->name }}</a> &raquo; Upravit
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Upravit skupinu: {{ $group->name }}</h2></div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

<form method="POST" action="{{ route('aro-groups.update', $group->id) }}">
@csrf
@method('PUT')
<div class="m-card" style="margin-bottom:16px;max-width:420px">
    <div class="m-form-group">
        <label class="m-form-label">Název skupiny</label>
        <input class="m-form-input" type="text" name="name" value="{{ old('name', $group->name) }}">
        @error('name') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    <div class="m-form-group">
        <label class="m-form-label">Nadřazená skupina</label>
        <select class="m-form-select" name="parent_id">
            @foreach($groups as $g)
                <option value="{{ $g->id }}" {{ old('parent_id', $group->parent_id) == $g->id ? 'selected' : '' }}>
                    {{ $g->name }}
                </option>
            @endforeach
        </select>
        @error('parent_id') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
</div>
<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Uložit</button>
    <a class="m-btn" href="{{ route('aro-groups.show', $group->id) }}">Zrušit</a>
</div>
</form>
</div>
@endsection
