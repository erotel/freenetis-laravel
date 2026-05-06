@extends('layouts.app')
@section('title', 'Bankovní účet: ' . $account->name)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('bank_accounts.index') }}">Bankovní účty</a> &raquo; {{ $account->name }}
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>{{ $account->name }}</h2></div>

<div class="m-actions">
    @if($canViewTransfers) <a class="m-btn" href="{{ route('bank_transfers.by_account', $account->id) }}">Převody</a> @endif
    @if($canViewStatements)
    <a class="m-btn" href="{{ route('bank_statements.by_account', $account->id) }}">Výpisy</a>
    <a class="m-btn" href="{{ route('import.upload_bank_file', $account->id) }}">Nahrát výpis</a>
    @endif
    @if($canEdit) <a class="m-btn" href="{{ route('bank_accounts.edit', $account->id) }}">Upravit</a> @endif
    @if($canManageAutoDown) <a class="m-btn" href="{{ route('bank_accounts.auto_downloads', $account->id) }}">Automatické stahování</a> @endif
</div>

@if($hasFioToken)
<div class="m-card" style="margin-bottom:16px;max-width:480px">
    <div class="m-card-title">FIO API import</div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <form method="POST" action="{{ route('import.fio_last', $account->id) }}" style="display:inline">
            @csrf
            <button class="m-btn m-btn-primary" type="submit"
                    onclick="return confirm('Stáhnout nové transakce z FIO API?')">
                &#8635; Načíst nové transakce
            </button>
        </form>
    </div>
    <details style="margin-top:10px">
        <summary style="cursor:pointer;color:#c0392b;font-size:16px">Načíst za období...</summary>
        <form method="POST" action="{{ route('import.fio_period', $account->id) }}" style="display:flex;gap:8px;align-items:center;margin-top:8px;flex-wrap:wrap">
            @csrf
            <label style="font-size:16px">Od: <input class="m-form-input" type="date" name="date_from" required style="max-width:150px"></label>
            <label style="font-size:16px">Do: <input class="m-form-input" type="date" name="date_to" required style="max-width:150px"></label>
            <button class="m-btn m-btn-primary" type="submit">Načíst</button>
        </form>
    </details>
</div>
@endif

<div class="m-card" style="max-width:420px">
    <div class="m-card-title">Informace o bankovním účtu</div>
    <div class="m-field"><span class="m-field-label">ID</span><span class="m-field-value">{{ $account->id }}</span></div>
    <div class="m-field"><span class="m-field-label">Název</span><span class="m-field-value">{{ $account->name }}</span></div>
    <div class="m-field"><span class="m-field-label">Číslo účtu</span><span class="m-field-value" style="font-family:monospace">{{ $account->account_nr ?: '—' }}</span></div>
    <div class="m-field"><span class="m-field-label">Kód banky</span><span class="m-field-value" style="font-family:monospace">{{ $account->bank_nr ?: '—' }}</span></div>
    <div class="m-field"><span class="m-field-label">Celé číslo</span><span class="m-field-value" style="font-family:monospace">{{ $account->full_account_number }}</span></div>
    <div class="m-field"><span class="m-field-label">IBAN</span><span class="m-field-value" style="font-family:monospace">{{ $account->IBAN ?: '—' }}</span></div>
    <div class="m-field"><span class="m-field-label">SWIFT</span><span class="m-field-value" style="font-family:monospace">{{ $account->SWIFT ?: '—' }}</span></div>
    <div class="m-field">
        <span class="m-field-label">Člen</span>
        <span class="m-field-value">
            @if($account->member) <a href="{{ route('members.show', $account->member_id) }}">{{ $account->member->name }}</a>
            @else — @endif
        </span>
    </div>
</div>
</div>
@endsection
