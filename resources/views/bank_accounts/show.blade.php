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

    @if($canViewStatements && $account->bankStatements->isNotEmpty())
        <h3>Výpisy (posledních 10)</h3>
        <table class="extended" cellspacing="0">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Typ</th>
                    <th>Od</th>
                    <th>Do</th>
                    <th>Počáteční zůstatek</th>
                    <th>Konečný zůstatek</th>
                </tr>
            </thead>
            <tbody>
                @foreach($account->bankStatements as $stmt)
                    <tr>
                        <td>{{ $stmt->id }}</td>
                        <td>{{ $stmt->type ?: '—' }}</td>
                        <td>{{ $stmt->from?->format('d.m.Y') }}</td>
                        <td>{{ $stmt->to?->format('d.m.Y') }}</td>
                        <td style="text-align:right;">{{ number_format($stmt->opening_balance, 2, ',', ' ') }} Kč</td>
                        <td style="text-align:right;">{{ number_format($stmt->closing_balance, 2, ',', ' ') }} Kč</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
