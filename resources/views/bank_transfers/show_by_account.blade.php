@extends('layouts.app')

@section('title', 'Převody: ' . $account->name)

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('bank_accounts.index') }}">Bankovní účty</a> &raquo;
        <a href="{{ route('bank_accounts.show', $account->id) }}">{{ $account->name }}</a> &raquo;
        Převody
    </div>
@endsection

@section('content')
    <h2>Převody na bankovním účtu: {{ $account->name }}</h2>

    <div style="margin-bottom:1em;">
        <a href="{{ route('bank_accounts.show', $account->id) }}">&larr; Zpět na účet</a>
    </div>

    <table class="extended" cellspacing="0" style="float:left; margin-right:20px;">
        <tbody>
            <tr><th>Číslo účtu</th><td>{{ $account->full_account_number }}</td></tr>
            <tr><th>IBAN</th><td>{{ $account->IBAN ?: '—' }}</td></tr>
        </tbody>
    </table>
    <div style="clear:both;"></div>

    <table class="extended" cellspacing="0">
        <thead>
            <tr>
                <th>ID</th>
                <th>Výpis</th>
                <th>Protiúčet</th>
                <th>VS</th>
                <th>KS</th>
                <th>Text</th>
                <th>Stav</th>
                <th>Akce</th>
            </tr>
        </thead>
        <tbody>
            @forelse($transfers as $bt)
                @php
                    $isIncoming = $bt->destination_id == $account->id;
                    $counterpart = $isIncoming ? $bt->originAccount : $bt->destinationAccount;
                @endphp
                <tr>
                    <td>{{ $bt->id }}</td>
                    <td>
                        @if($bt->bankStatement)
                            {{ $bt->bankStatement->from?->format('d.m.Y') }}
                        @else
                            —
                        @endif
                    </td>
                    <td>
                        @if($counterpart)
                            <a href="{{ route('bank_accounts.show', $counterpart->id) }}">
                                {{ $counterpart->full_account_number }}
                            </a>
                        @else
                            —
                        @endif
                    </td>
                    <td>{{ $bt->variable_symbol ?: '—' }}</td>
                    <td>{{ $bt->constant_symbol ?: '—' }}</td>
                    <td>{{ $bt->comment ?: '—' }}</td>
                    <td>
                        @if($bt->transfer_id)
                            <a href="{{ route('transfers.show', $bt->transfer_id) }}"
                               style="color:green;">Spárováno</a>
                        @else
                            <span style="color:#c00;">Nespárováno</span>
                        @endif
                    </td>
                    <td class="action">
                        @if($bt->transfer_id)
                            <a href="{{ route('transfers.show', $bt->transfer_id) }}" title="Převod">
                                <img src="{{ asset('media/images/icons/con_info.png') }}" alt="Detail">
                            </a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8">Žádné převody.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="pagination-wrap">{{ $transfers->links() }}</div>
@endsection
