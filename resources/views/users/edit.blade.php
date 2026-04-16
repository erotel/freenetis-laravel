@extends('layouts.app')
@section('title', 'Upravit uživatele: ' . $user->full_name)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('users.index') }}">Uživatelé</a> &raquo;
    <a href="{{ route('users.show', $user->id) }}">{{ $user->full_name }}</a> &raquo;
    Upravit
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Upravit uživatele: {{ $user->full_name }}</h2></div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

<form method="POST" action="{{ route('users.update', $user->id) }}">
@csrf
@method('PUT')
<div class="m-card" style="margin-bottom:16px;max-width:560px">
    <div class="m-card-title">Základní informace</div>
    @if($canEditMember)
    <div class="m-form-group">
        <label class="m-form-label" for="member_id">Člen <span style="color:#c0392b">*</span></label>
        <select class="m-form-select" id="member_id" name="member_id">
            @foreach($members as $member)
                <option value="{{ $member->id }}" @selected(old('member_id', $user->member_id) == $member->id)>
                    {{ $member->name }}
                </option>
            @endforeach
        </select>
        @error('member_id') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    @endif
    @if($canEditLogin)
    <div class="m-form-group">
        <label class="m-form-label" for="login">Login <span style="color:#c0392b">*</span></label>
        <input class="m-form-input" type="text" id="login" name="login" value="{{ old('login', $user->login) }}" maxlength="100">
        @error('login') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    @else
    <div class="m-field">
        <span class="m-field-label">Login</span>
        <span class="m-field-value">{{ $user->login }}</span>
    </div>
    @endif
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="pre_title">Titul před</label>
            <input class="m-form-input" type="text" id="pre_title" name="pre_title" value="{{ old('pre_title', $user->pre_title) }}" maxlength="50">
            @error('pre_title') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="post_title">Titul za</label>
            <input class="m-form-input" type="text" id="post_title" name="post_title" value="{{ old('post_title', $user->post_title) }}" maxlength="50">
            @error('post_title') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="name">Jméno</label>
            <input class="m-form-input" type="text" id="name" name="name" value="{{ old('name', $user->name) }}" maxlength="100">
            @error('name') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="middle_name">Druhé jméno</label>
            <input class="m-form-input" type="text" id="middle_name" name="middle_name" value="{{ old('middle_name', $user->middle_name) }}" maxlength="100">
            @error('middle_name') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="surname">Příjmení <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="text" id="surname" name="surname" value="{{ old('surname', $user->surname) }}" maxlength="100">
            @error('surname') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="birthday">Datum narození</label>
            <input class="m-form-input" type="date" id="birthday" name="birthday" value="{{ old('birthday', $user->birthday) }}">
            @error('birthday') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="type">Typ <span style="color:#c0392b">*</span></label>
            <select class="m-form-select" id="type" name="type">
                <option value="1" @selected(old('type', $user->type) == 1)>Hlavní uživatel</option>
                <option value="2" @selected(old('type', $user->type) == 2)>Uživatel</option>
            </select>
            @error('type') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div></div>
    </div>
    @if($canEditComment)
    <div class="m-form-group">
        <label class="m-form-label" for="comment">Komentář</label>
        <textarea class="m-form-input" id="comment" name="comment" rows="3" maxlength="250">{{ old('comment', $user->comment) }}</textarea>
        @error('comment') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    @endif
</div>
<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Uložit</button>
    <a class="m-btn" href="{{ route('users.show', $user->id) }}">Zrušit</a>
</div>
</form>
</div>
@endsection
