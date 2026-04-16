@extends('layouts.app')
@section('title', 'Účet: ' . $account->name)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('accounts.index') }}">Účty</a> &raquo; {{ $account->name }}
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>{{ $account->name }}</h2></div>

<div class="m-actions">
    @if($canEdit) <a class="m-btn" href="{{ route('accounts.edit', $account->id) }}">Upravit</a> @endif
    @if($canViewTransfers) <a class="m-btn" href="{{ route('transfers.by_account', $account->id) }}">Zobrazit převody</a> @endif
</div>

<div class="m-metrics">
    <div class="m-metric">
        <div class="m-metric-label">Zůstatek</div>
        <div class="m-metric-value {{ $account->balance > 0 ? 'green' : ($account->balance < 0 ? 'red' : '') }}">
            {{ number_format($account->balance, 2, ',', ' ') }} Kč
        </div>
    </div>
    <div class="m-metric">
        <div class="m-metric-label">Celkem příchozí</div>
        <div class="m-metric-value green sm">{{ number_format($totalIn, 2, ',', ' ') }} Kč</div>
    </div>
    <div class="m-metric">
        <div class="m-metric-label">Celkem odchozí</div>
        <div class="m-metric-value red sm">{{ number_format($totalOut, 2, ',', ' ') }} Kč</div>
    </div>
</div>

<div class="m-card" style="max-width:420px">
    <div class="m-card-title">Informace o účtu</div>
    <div class="m-field"><span class="m-field-label">ID</span><span class="m-field-value">{{ $account->id }}</span></div>
    <div class="m-field"><span class="m-field-label">Název</span><span class="m-field-value">{{ $account->name }}</span></div>
    <div class="m-field"><span class="m-field-label">Typ účtu</span><span class="m-field-value">{{ $account->accountAttribute?->name ?? '—' }}</span></div>
    <div class="m-field">
        <span class="m-field-label">Člen</span>
        <span class="m-field-value">
            @if($account->member) <a href="{{ route('members.show', $account->member_id) }}">{{ $account->member->name }}</a>
            @else — @endif
        </span>
    </div>
    @if($account->comment)
    <div class="m-field"><span class="m-field-label">Komentář</span><span class="m-field-value">{{ $account->comment }}</span></div>
    @endif
</div>
</div>
@endsection
