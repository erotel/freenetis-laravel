@extends('layouts.app')
@section('title', 'Upravit VLAN: ' . $vlan)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('vlans.index') }}">VLANy</a> &raquo;
    <a href="{{ route('vlans.show', $vlan->id) }}">{{ (string)$vlan }}</a> &raquo;
    Upravit
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Upravit VLAN: {{ (string)$vlan }}</h2></div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

<form method="POST" action="{{ route('vlans.update', $vlan->id) }}">
@csrf
@method('PUT')
<div class="m-card" style="margin-bottom:16px;max-width:420px">
    <div class="m-form-group">
        <label class="m-form-label" for="tag_802_1q">802.1Q tag <span style="color:#c0392b">*</span></label>
        <input class="m-form-input" type="number" id="tag_802_1q" name="tag_802_1q"
               value="{{ old('tag_802_1q', $vlan->tag_802_1q) }}" min="1" max="4094" style="max-width:120px">
        @error('tag_802_1q') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    <div class="m-form-group">
        <label class="m-form-label" for="name">Název</label>
        <input class="m-form-input" type="text" id="name" name="name"
               value="{{ old('name', $vlan->name) }}" maxlength="254">
        @error('name') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    <div class="m-form-group">
        <label class="m-form-label" for="comment">Komentář</label>
        <textarea class="m-form-input" id="comment" name="comment" rows="3" maxlength="254">{{ old('comment', $vlan->comment) }}</textarea>
        @error('comment') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
</div>
<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Uložit</button>
    <a class="m-btn" href="{{ route('vlans.show', $vlan->id) }}">Zrušit</a>
</div>
</form>
</div>
@endsection
