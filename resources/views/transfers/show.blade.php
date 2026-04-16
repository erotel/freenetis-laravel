@extends('layouts.app')
@section('title', 'Převod #' . $transfer->id)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('transfers.index') }}">Převody</a> &raquo; #{{ $transfer->id }}
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Převod #{{ $transfer->id }}</h2></div>

<div class="m-actions">
    <a class="m-btn" href="{{ route('transfers.index') }}">&larr; Zpět na deník</a>
</div>

<div class="m-card" style="max-width:480px">
    <div class="m-card-title">Detail převodu</div>
    <div class="m-field"><span class="m-field-label">ID</span><span class="m-field-value">{{ $transfer->id }}</span></div>
    <div class="m-field"><span class="m-field-label">Datum efektivní</span><span class="m-field-value">{{ $transfer->datetime?->format('d.m.Y H:i') }}</span></div>
    <div class="m-field"><span class="m-field-label">Datum vytvoření</span><span class="m-field-value">{{ $transfer->creation_datetime?->format('d.m.Y H:i') }}</span></div>
    <div class="m-field"><span class="m-field-label">Popis</span><span class="m-field-value">{{ $transfer->text ?? '—' }}</span></div>
    <div class="m-field"><span class="m-field-label">Typ</span><span class="m-field-value">{{ \App\Models\Transfer::typeLabel($transfer->type ?? 0) }}</span></div>
    <div class="m-field">
        <span class="m-field-label">Částka</span>
        <span class="m-field-value" style="font-family:monospace">{{ number_format($transfer->amount, 2, ',', ' ') }} Kč</span>
    </div>
    <div class="m-field">
        <span class="m-field-label">Z účtu</span>
        <span class="m-field-value">
            @if($transfer->origin) <a href="{{ route('accounts.show', $transfer->origin_id) }}">{{ $transfer->origin->name }}</a>
            @else — @endif
        </span>
    </div>
    <div class="m-field">
        <span class="m-field-label">Na účet</span>
        <span class="m-field-value">
            @if($transfer->destination) <a href="{{ route('accounts.show', $transfer->destination_id) }}">{{ $transfer->destination->name }}</a>
            @else — @endif
        </span>
    </div>
    <div class="m-field">
        <span class="m-field-label">Člen</span>
        <span class="m-field-value">
            @if($transfer->member) <a href="{{ route('members.show', $transfer->member_id) }}">{{ $transfer->member->name }}</a>
            @else — @endif
        </span>
    </div>
    <div class="m-field">
        <span class="m-field-label">Vytvořil</span>
        <span class="m-field-value">
            @if($transfer->user) <a href="{{ route('users.show', $transfer->user_id) }}">{{ $transfer->user->name }}</a>
            @else — @endif
        </span>
    </div>
    @if($transfer->previousTransfer)
    <div class="m-field">
        <span class="m-field-label">Předchozí převod</span>
        <span class="m-field-value">
            <a href="{{ route('transfers.show', $transfer->previousTransfer->id) }}">#{{ $transfer->previousTransfer->id }}</a>
            — {{ $transfer->previousTransfer->text }}
        </span>
    </div>
    @endif
</div>
</div>
@endsection
