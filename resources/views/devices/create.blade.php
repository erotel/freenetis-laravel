@extends('layouts.app')
@section('title', 'Přidat zařízení')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('devices.index') }}">Zařízení</a> &raquo; Přidat zařízení
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Přidat zařízení</h2></div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

<form method="POST" action="{{ route('devices.store') }}">
@csrf
<div class="m-card" style="margin-bottom:16px;max-width:560px">
    <div class="m-form-group">
        <label class="m-form-label" for="member_id">Člen <span style="color:#c0392b">*</span></label>
        @if($preselectedMemberId)
            <input type="hidden" name="member_id" value="{{ $preselectedMemberId }}">
            <div class="m-form-input" style="background:var(--fn-quote-bg);color:var(--fn-text);cursor:default">{{ $members->firstWhere('id', $preselectedMemberId)?->display_name ?? $preselectedMemberId }}</div>
        @else
            <select class="m-form-select" id="member_id" name="member_id">
                <option value="">— vyberte člena —</option>
                @foreach($members as $m)
                    <option value="{{ $m->id }}" @selected(old('member_id') == $m->id)>
                        {{ $m->display_name }} ({{ $m->login }})
                    </option>
                @endforeach
            </select>
        @endif
        @error('member_id') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="name">Název <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="text" id="name" name="name" value="{{ old('name') }}" maxlength="255">
            @error('name') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="type">Typ <span style="color:#c0392b">*</span></label>
            <select class="m-form-select" id="type" name="type">
                <option value="">— vyberte typ —</option>
                @foreach($deviceTypes as $id => $label)
                    <option value="{{ $id }}" @selected(old('type') == $id)>{{ $label }}</option>
                @endforeach
            </select>
            @error('type') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="trade_name">Obchodní název</label>
            <input class="m-form-input" type="text" id="trade_name" name="trade_name" value="{{ old('trade_name') }}" maxlength="50">
            @error('trade_name') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="buy_date">Datum koupě</label>
            <input class="m-form-input" type="date" id="buy_date" name="buy_date" value="{{ old('buy_date', date('Y-m-d')) }}">
            @error('buy_date') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="login">Login</label>
            <input class="m-form-input" type="text" id="login" name="login" value="{{ old('login') }}" maxlength="30">
            @error('login') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="password">Heslo</label>
            <input class="m-form-input" type="text" id="password" name="password" value="{{ old('password') }}" maxlength="30">
            @error('password') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="price">Cena</label>
            <input class="m-form-input" type="number" step="0.01" id="price" name="price" value="{{ old('price') }}">
            @error('price') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="payment_rate">Měsíční splátka</label>
            <input class="m-form-input" type="number" step="0.01" id="payment_rate" name="payment_rate" value="{{ old('payment_rate') }}">
            @error('payment_rate') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="m-form-group">
        <label class="m-form-label" for="comment">Komentář</label>
        <textarea class="m-form-input" id="comment" name="comment" rows="3" maxlength="254">{{ old('comment') }}</textarea>
        @error('comment') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
</div>
<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Přidat</button>
    <a class="m-btn" href="{{ route('devices.index') }}">Zrušit</a>
</div>
</form>
</div>
@endsection
