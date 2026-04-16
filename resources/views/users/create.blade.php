@extends('layouts.app')
@section('title', 'Přidat uživatele')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('users.index') }}">Uživatelé</a> &raquo; Přidat nového uživatele
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Přidat nového uživatele</h2></div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

<form method="POST" action="{{ route('users.store') }}">
@csrf
<div class="m-card" style="margin-bottom:16px;max-width:560px">
    <div class="m-card-title">Základní informace</div>
    <div class="m-form-group">
        <label class="m-form-label" for="member_id">Člen <span style="color:#c0392b">*</span></label>
        @if($member)
            <input type="hidden" name="member_id" value="{{ $member->id }}">
            <div class="m-form-input" style="background:#f7f7f5;cursor:default">{{ $member->name }}</div>
        @else
            <select class="m-form-select" id="member_id" name="member_id">
                <option value="">— vyberte člena —</option>
                @foreach($members as $m)
                    <option value="{{ $m->id }}" @selected(old('member_id') == $m->id)>{{ $m->name }}</option>
                @endforeach
            </select>
        @endif
        @error('member_id') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    @if($canEditLogin)
    <div class="m-form-group">
        <label class="m-form-label" for="login">Login <span style="color:#c0392b">*</span></label>
        <input class="m-form-input" type="text" id="login" name="login" value="{{ old('login') }}" maxlength="100">
        @error('login') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    @endif
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="password">Heslo <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="password" id="password" name="password" autocomplete="new-password">
            @error('password') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="password_confirmation">Potvrdit heslo <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password">
        </div>
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="pre_title">Titul před</label>
            <input class="m-form-input" type="text" id="pre_title" name="pre_title" value="{{ old('pre_title') }}" maxlength="50">
            @error('pre_title') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="post_title">Titul za</label>
            <input class="m-form-input" type="text" id="post_title" name="post_title" value="{{ old('post_title') }}" maxlength="50">
            @error('post_title') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="name">Jméno</label>
            <input class="m-form-input" type="text" id="name" name="name" value="{{ old('name') }}" maxlength="100">
            @error('name') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="middle_name">Druhé jméno</label>
            <input class="m-form-input" type="text" id="middle_name" name="middle_name" value="{{ old('middle_name') }}" maxlength="100">
            @error('middle_name') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="surname">Příjmení <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="text" id="surname" name="surname" value="{{ old('surname') }}" maxlength="100">
            @error('surname') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="birthday">Datum narození</label>
            <input class="m-form-input" type="date" id="birthday" name="birthday" value="{{ old('birthday') }}">
            @error('birthday') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="type">Typ <span style="color:#c0392b">*</span></label>
            <select class="m-form-select" id="type" name="type">
                <option value="1" @selected(old('type', 2) == 1)>Hlavní uživatel</option>
                <option value="2" @selected(old('type', 2) == 2)>Uživatel</option>
            </select>
            @error('type') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div></div>
    </div>
    <div class="m-form-group">
        <label class="m-form-label" for="comment">Poznámka</label>
        <textarea class="m-form-input" id="comment" name="comment" rows="3" maxlength="250">{{ old('comment') }}</textarea>
        @error('comment') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
</div>
<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Přidat</button>
    <a class="m-btn" href="{{ route('users.index') }}">Zrušit</a>
</div>
</form>
</div>
@endsection
