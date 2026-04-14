@extends('layouts.app')

@section('title', 'Bankovní účet: ' . $account->name)

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('bank_accounts.index') }}">Bankovní účty</a> &raquo;
        {{ $account->name }}
    </div>
@endsection

@section('content')
    <h2>{{ $account->name }}</h2>

    <div style="margin-bottom:1em;">
        @if($canViewTransfers)
            <a href="{{ route('bank_transfers.by_account', $account->id) }}">Zobrazit převody</a>
        @endif
        @if($canViewStatements)
            &nbsp;|&nbsp;
            <a href="{{ route('bank_statements.by_account', $account->id) }}">Výpisy</a>
            &nbsp;|&nbsp;
            <a href="{{ route('import.upload_bank_file', $account->id) }}">Nahrát bankovní výpis</a>
        @endif
        @if($canEdit)
            &nbsp;|&nbsp;
            <a href="{{ route('bank_accounts.edit', $account->id) }}">Upravit</a>
        @endif
        @if($canManageAutoDown)
            &nbsp;|&nbsp;
            <a href="{{ route('bank_accounts.auto_downloads', $account->id) }}">Automatické stahování</a>
        @endif
    </div>

    @if($hasFioToken)
        <div style="margin-bottom:1em; padding:10px; background:#f5f5f5; border:1px solid #ddd;">
            <strong>FIO API import</strong><br><br>

            <form method="POST" action="{{ route('import.fio_last', $account->id) }}" style="display:inline;">
                @csrf
                <button type="submit"
                        onclick="return confirm('Stáhnout nové transakce z FIO API?')">
                    &#8635; Načíst nové transakce
                </button>
            </form>

            <details style="margin-top:8px;">
                <summary style="cursor:pointer; color:#a00;">Načíst za období...</summary>
                <form method="POST" action="{{ route('import.fio_period', $account->id) }}"
                      style="margin-top:8px;">
                    @csrf
                    Od: <input type="date" name="date_from" required>
                    Do: <input type="date" name="date_to" required>
                    <button type="submit">Načíst</button>
                </form>
            </details>
        </div>
    @endif

    <table class="extended" cellspacing="0">
        <thead>
            <tr><th colspan="2">Informace o bankovním účtu</th></tr>
        </thead>
        <tbody>
            <tr><th>ID</th><td>{{ $account->id }}</td></tr>
            <tr><th>Název</th><td>{{ $account->name }}</td></tr>
            <tr><th>Číslo účtu</th><td>{{ $account->account_nr ?: '—' }}</td></tr>
            <tr><th>Kód banky</th><td>{{ $account->bank_nr ?: '—' }}</td></tr>
            <tr><th>Celé číslo</th><td>{{ $account->full_account_number }}</td></tr>
            <tr><th>IBAN</th><td>{{ $account->IBAN ?: '—' }}</td></tr>
            <tr><th>SWIFT</th><td>{{ $account->SWIFT ?: '—' }}</td></tr>
            <tr>
                <th>Člen</th>
                <td>
                    @if($account->member)
                        <a href="{{ route('members.show', $account->member_id) }}">{{ $account->member->name }}</a>
                    @else
                        —
                    @endif
                </td>
            </tr>
        </tbody>
    </table>

@endsection
