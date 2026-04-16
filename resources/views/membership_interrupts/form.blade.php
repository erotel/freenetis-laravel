@extends('layouts.app')
@section('title', $action === 'create' ? 'Přidat přerušení členství' : 'Upravit přerušení členství')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('members.index') }}">Členové</a> &raquo;
    <a href="{{ route('members.show', $member->id) }}">{{ $member->name }}</a> &raquo;
    {{ $action === 'create' ? 'Přidat přerušení členství' : 'Upravit přerušení členství' }}
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row">
    <h2>{{ $action === 'create' ? 'Přidat přerušení členství' : 'Upravit přerušení členství' }} — {{ $member->name }}</h2>
</div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

@if($action === 'create')
<form method="POST" action="{{ route('membership-interrupts.store', $member->id) }}">
@else
<form method="POST" action="{{ route('membership-interrupts.update', $record->id) }}">
@method('PUT')
@endif
@csrf

<div class="m-card" style="margin-bottom:16px;max-width:480px">
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="from">Datum od <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="date" id="from" name="from"
                   value="{{ old('from', $record ? substr($record->activation_date, 0, 10) : '') }}" required>
            <div class="m-form-hint">První den přerušení.</div>
            @error('from') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="to">Datum do <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="date" id="to" name="to"
                   value="{{ old('to', $record ? substr($record->deactivation_date, 0, 10) : '') }}" required>
            <div class="m-form-hint">Poslední den přerušení.</div>
            @error('to') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="m-form-group">
        <label class="m-form-label" for="comment">Komentář <span style="color:#c0392b">*</span></label>
        <textarea class="m-form-input" id="comment" name="comment" rows="3" maxlength="250">{{ old('comment', $record?->comment) }}</textarea>
        @error('comment') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    <div class="m-form-group">
        @php
            $checked = old('end_after_interrupt_end') !== null
                ? (bool) old('end_after_interrupt_end')
                : ($record ? (bool) $record->end_after_interrupt_end : false);
        @endphp
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
            <input type="checkbox" id="end_after_interrupt_end" name="end_after_interrupt_end" value="1" @checked($checked)>
            Ukončit členství po skončení přerušení
        </label>
        <div class="m-form-hint">Nastaví datum odchodu člena na datum konce přerušení.</div>
    </div>
</div>

<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Uložit</button>
    <a class="m-btn" href="{{ route('members.show', $member->id) }}">Zrušit</a>
</div>
</form>
</div>
@endsection
