@extends('layouts.app')
@section('title', 'Upravit účet: ' . $account->name)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('accounts.index') }}">Účty</a> &raquo;
    <a href="{{ route('accounts.show', $account->id) }}">{{ $account->name }}</a> &raquo;
    Upravit
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Upravit účet: {{ $account->name }}</h2></div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

<form method="POST" action="{{ route('accounts.update', $account->id) }}">
@csrf
@method('PUT')
<div class="m-card" style="margin-bottom:16px;max-width:420px">
    <div class="m-field" style="margin-bottom:12px">
        <span class="m-field-label">Typ účtu</span>
        <span class="m-field-value"><strong>{{ $account->accountAttribute?->name ?? '—' }}</strong></span>
    </div>
    <div class="m-form-group">
        <label class="m-form-label" for="name">Název <span style="color:#c0392b">*</span></label>
        <input class="m-form-input" type="text" id="name" name="name"
               value="{{ old('name', $account->name) }}" maxlength="100">
        @error('name') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    <div class="m-form-group">
        <label class="m-form-label" for="comment">Komentář</label>
        <textarea class="m-form-input" id="comment" name="comment" rows="3" maxlength="254">{{ old('comment', $account->comment) }}</textarea>
        @error('comment') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
</div>
<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Uložit</button>
    <a class="m-btn" href="{{ route('accounts.show', $account->id) }}">Zrušit</a>
</div>
</form>
</div>
@endsection
