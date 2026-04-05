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
    </div>

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
