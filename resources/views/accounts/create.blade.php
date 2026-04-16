@extends('layouts.app')
@section('title', 'Přidat projektový účet')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('accounts.index', ['type' => 'project']) }}">Účty</a> &raquo; Přidat projektový účet
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Přidat projektový účet</h2></div>
<div class="m-subtitle">Projektové účty slouží k evidenci specifických rozpočtových položek.</div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

<form method="POST" action="{{ route('accounts.store') }}">
@csrf
<div class="m-card" style="margin-bottom:16px;max-width:420px">
    <div class="m-form-group">
        <label class="m-form-label" for="name">Název <span style="color:#c0392b">*</span></label>
        <input class="m-form-input" type="text" id="name" name="name" value="{{ old('name') }}" maxlength="100">
        @error('name') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    <div class="m-form-group">
        <label class="m-form-label" for="comment">Komentář</label>
        <textarea class="m-form-input" id="comment" name="comment" rows="3" maxlength="254">{{ old('comment') }}</textarea>
        @error('comment') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
</div>
<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Přidat</button>
    <a class="m-btn" href="{{ route('accounts.index', ['type' => 'project']) }}">Zrušit</a>
</div>
</form>
</div>
@endsection
