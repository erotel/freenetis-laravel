@extends('layouts.app')
@section('title', $action === 'create' ? 'Přidat komentář' : 'Upravit komentář')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    @if($backUrl) <a href="{{ $backUrl }}">Zpět</a> &raquo; @endif
    {{ $action === 'create' ? 'Přidat komentář' : 'Upravit komentář' }}
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row">
    <h2>{{ $action === 'create' ? 'Přidat komentář k finančnímu stavu člena' : 'Upravit komentář' }}</h2>
</div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

@if($action === 'create')
<form method="POST" action="{{ route('comments.store', $thread->id) }}">
@else
<form method="POST" action="{{ route('comments.update', $comment->id) }}">
@method('PUT')
@endif
@csrf

<div class="m-card" style="margin-bottom:16px;max-width:540px">
    <div class="m-form-group">
        <label class="m-form-label" for="text">Komentář <span style="color:#c0392b">*</span></label>
        <textarea class="m-form-input" id="text" name="text" rows="5" maxlength="5000">{{ old('text', $comment?->text) }}</textarea>
        @error('text') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
</div>

<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Uložit</button>
    @if($backUrl) <a class="m-btn" href="{{ $backUrl }}">Zrušit</a> @endif
</div>
</form>
</div>
@endsection
