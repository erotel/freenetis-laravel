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

    <p>Tyto bankovní převody nemají přiřazený systémový převod (nebyly spárovány).</p>

    <table class="extended" cellspacing="0">
        <thead>
            <tr>
                <th>ID</th>
                <th>Bankovní účet</th>
                <th>Výpis / Datum</th>
                <th>Protiúčet</th>
                <th style="color:#c00;">VS</th>
                <th>KS</th>
                <th>Transakční kód</th>
                <th>Text</th>
                <th>Akce</th>
            </tr>
        </thead>
        <tbody>
            @forelse($transfers as $bt)
                @php
                    $mainAccount = $bt->bankStatement?->bankAccount;
                    $isIncoming  = $bt->destination_id == $mainAccount?->id;
                    $counterpart = $isIncoming ? $bt->originAccount : $bt->destinationAccount;
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
                    <td>
                        @if($counterpart)
                            {{ $counterpart->full_account_number }}
                        @else
                            —
                        @endif
                    </td>
                    <td style="color:{{ $bt->variable_symbol ? 'inherit' : '#c00' }}">
                        {{ $bt->variable_symbol ?: '—' }}
                    </td>
                    <td>{{ $bt->constant_symbol ?: '—' }}</td>
                    <td>{{ $bt->transaction_code ?: '—' }}</td>
                    <td>{{ $bt->comment ?: '—' }}</td>
                    <td class="action">
                        {{-- Placeholder: future "Zadat vrácení" --}}
                    </td>
                </tr>
            @empty
                <tr><td colspan="9">Žádné neidentifikované převody.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="pagination-wrap">{{ $transfers->links() }}</div>
@endsection
