@extends('layouts.app')
@section('title', 'Změnit heslo')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('members.index') }}">Členové</a> &raquo;
    @if($member)
    <a href="{{ route('members.show', $member->id) }}">{{ $member->name }}</a> &raquo;
    @endif
    Změnit heslo
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Změnit heslo: {{ $user->full_name }}</h2></div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">
        @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
    </ul>
</div>
@endif

<form method="POST" action="{{ route('users.password.update', $user->id) }}">
@csrf
@method('PUT')
<div class="m-card" style="margin-bottom:16px;max-width:420px">
    @if($isSelf && !$isAdmin)
    <div class="m-form-group">
        <label class="m-form-label" for="current_password">Aktuální heslo <span style="color:#c0392b">*</span></label>
        <input class="m-form-input" type="password" id="current_password" name="current_password" autocomplete="current-password">
        @error('current_password') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    @endif
    <div class="m-form-group">
        <label class="m-form-label" for="password">Nové heslo <span style="color:#c0392b">*</span></label>
        <input class="m-form-input" type="password" id="password" name="password" autocomplete="new-password">
        @error('password') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    <div class="m-form-group">
        <label class="m-form-label" for="password_confirmation">Heslo znovu <span style="color:#c0392b">*</span></label>
        <input class="m-form-input" type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password">
    </div>
</div>
<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Uložit heslo</button>
    @if($member)
    <a class="m-btn" href="{{ route('members.show', $member->id) }}">Zrušit</a>
    @else
    <a class="m-btn" href="{{ route('users.show', $user->id) }}">Zrušit</a>
    @endif
</div>
</form>
</div>
@endsection
