@extends('layouts.app')

@section('title', 'Neidentifikované převody')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('bank_accounts.index') }}">Bankovní účty</a> &raquo;
        Neidentifikované převody
    </div>
@endsection

@section('content')
    <h2>Neidentifikované převody</h2>

    <p>Tyto bankovní převody nebyly spárovány s žádným členem (chybí VS nebo přišly na nesprávný účet).</p>

    <table class="extended" cellspacing="0">
        <thead>
            <tr>
                <th>ID</th>
                <th>Bankovní účet</th>
                <th>Datum</th>
                <th>Číslo protiúčtu</th>
                <th>Název účtu</th>
                <th style="color:#c00;">VS</th>
                <th>Částka</th>
                <th>Komentář</th>
                <th>Akce</th>
            </tr>
        </thead>
        <tbody>
            @forelse($transfers as $bt)
                @php
                    $mainAccount = $bt->bankStatement?->bankAccount;
                    $isIncoming  = $bt->destination_id == $mainAccount?->id;
                    $counterpart = $isIncoming ? $bt->originAccount : $bt->destinationAccount;
                    $amount      = $bt->transfer?->amount;
                @endphp
                <tr>
                    <td>{{ $bt->id }}</td>
                    <td>
                        @if($mainAccount)
                            <a href="{{ route('bank_accounts.show', $mainAccount->id) }}">{{ $mainAccount->name }}</a>
                        @else
                            —
                        @endif
                    </td>
                    <td>{{ $bt->bankStatement?->from?->format('d.m.Y') ?? '—' }}</td>
                    <td>{{ $counterpart?->full_account_number ?? '—' }}</td>
                    <td>{{ $counterpart?->name ?? '—' }}</td>
                    <td style="color:{{ $bt->variable_symbol ? 'inherit' : '#c00' }}">
                        {{ $bt->variable_symbol ?: '—' }}
                    </td>
                    <td>
                        @if($amount !== null)
                            {{ number_format($amount, 2, ',', ' ') }} Kč
                        @else
                            —
                        @endif
                    </td>
                    <td>{{ $bt->comment ?: '—' }}</td>
                    <td class="action">
                        {{-- Placeholder: future "Přiřadit" --}}
                    </td>
                </tr>
            @empty
                <tr><td colspan="9">Žádné neidentifikované převody.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="pagination-wrap">{{ $transfers->links() }}</div>
@endsection
