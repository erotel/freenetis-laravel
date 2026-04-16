@extends('layouts.app')
@section('title', 'Oznámení člena — ' . $member->name)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('members.index') }}">Členové</a> &raquo;
    <a href="{{ route('members.show', $member->id) }}">{{ $member->name }}</a> &raquo;
    Oznámení
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Nastavení oznámení člena {{ $member->name }}</h2></div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

<form method="POST" action="{{ route('notifications.member.notify', $member->id) }}">
@csrf
<div class="m-card" style="margin-bottom:16px;max-width:480px">
    <div class="m-form-group">
        <label class="m-form-label" for="message_id">Zpráva <span style="color:#c0392b">*</span></label>
        <select class="m-form-select" id="message_id" name="message_id">
            <option value="">— Vyberte zprávu —</option>
            @foreach($messages as $msgId => $msgName)
                <option value="{{ $msgId }}" @selected(old('message_id') == $msgId)>
                    {{ $msgName }}
                </option>
            @endforeach
        </select>
        @error('message_id') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    <div class="m-form-group">
        <label class="m-form-label" for="comment">Komentář</label>
        <textarea class="m-form-input" id="comment" name="comment" rows="3">{{ old('comment') }}</textarea>
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="redirection">Přesměrování</label>
            <select class="m-form-select" id="redirection" name="redirection">
                <option value="{{ $ACTIVATE }}" @selected(old('redirection', $ACTIVATE) == $ACTIVATE)>Aktivovat</option>
                <option value="{{ $KEEP }}" @selected(old('redirection') == $KEEP)>Ponechat</option>
                <option value="{{ $DEACTIVATE }}" @selected(old('redirection') == $DEACTIVATE)>Deaktivovat</option>
            </select>
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="email">E-mail</label>
            <select class="m-form-select" id="email" name="email">
                <option value="{{ $ACTIVATE }}" @selected(old('email', $ACTIVATE) == $ACTIVATE)>Odeslat</option>
                <option value="{{ $KEEP }}" @selected(old('email') == $KEEP)>Neposílat</option>
                <option value="{{ $DEACTIVATE }}" @selected(old('email') == $DEACTIVATE)>Deaktivovat</option>
            </select>
        </div>
    </div>
    <div class="m-form-group">
        <label class="m-form-label" for="sms">SMS</label>
        <select class="m-form-select" id="sms" name="sms" style="max-width:200px">
            <option value="{{ $ACTIVATE }}" @selected(old('sms', $ACTIVATE) == $ACTIVATE)>Odeslat</option>
            <option value="{{ $KEEP }}" @selected(old('sms') == $KEEP)>Neposílat</option>
            <option value="{{ $DEACTIVATE }}" @selected(old('sms') == $DEACTIVATE)>Deaktivovat</option>
        </select>
    </div>
</div>
<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Aktivovat</button>
    <a class="m-btn" href="{{ route('members.show', $member->id) }}">Zrušit</a>
</div>
</form>
</div>
@endsection
