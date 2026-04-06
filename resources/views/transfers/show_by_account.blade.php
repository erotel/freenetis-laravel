@extends('layouts.app')

@section('title', 'Převody účtu: ' . $account->name)

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('transfers.index') }}">Převody</a> &raquo;
        <a href="{{ route('accounts.show', $account->id) }}">{{ $account->name }}</a> &raquo;
        Převody účtu
    </div>
@endsection

@section('content')
    <h2>Převody účtu: {{ $account->name }}</h2>

    <div style="margin-bottom:4px;">
        <a href="{{ route('accounts.show', $account->id) }}">&larr; Zpět na účet</a>
    </div>

    <table class="extended" cellspacing="0" style="margin-bottom:4px;">
        <tbody>
            <tr>
                <th>Název</th>
                <td>{{ $account->name }}</td>
            </tr>
            <tr>
                <th>Typ</th>
                <td>{{ $account->accountAttribute?->name ?? '—' }}</td>
            </tr>
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
            <tr>
                <th>Zůstatek</th>
                <td style="color:{{ $account->balance > 0 ? 'green' : ($account->balance < 0 ? 'red' : 'inherit') }}">
                    {{ number_format($account->balance, 2, ',', ' ') }} Kč
                </td>
            </tr>
        </tbody>
    </table>

    <table class="extended" cellspacing="0">
        <thead>
            <tr>
                <th>ID</th>
                <th>Protiúčet</th>
                <th>Datum</th>
                <th style="text-align:right;">Částka</th>
                <th>Text</th>
                <th>Akce</th>
            </tr>
        </thead>
        <tbody>
            @forelse($transfers as $transfer)
                @php
                    $isOutgoing  = $transfer->origin_id === $accountId;
                    $counterpart = $isOutgoing ? $transfer->destination : $transfer->origin;
                    $signed      = $isOutgoing ? -$transfer->amount : $transfer->amount;
                    $amountColor = $signed >= 0 ? 'green' : 'red';
                @endphp
                <tr>
                    <td>{{ $transfer->id }}</td>
                    <td>
                        @if($counterpart)
                            <a href="{{ route('accounts.show', $counterpart->id) }}">{{ $counterpart->name }}</a>
                        @else
                            —
                        @endif
                    </td>
                    <td>{{ $transfer->datetime?->format('d.m.Y') }}</td>
                    <td style="text-align:right; color:{{ $amountColor }};">
                        {{ ($signed >= 0 ? '+' : '') . number_format($signed, 2, ',', ' ') }} Kč
                    </td>
                    <td>{{ $transfer->text }}</td>
                    <td>
                        <a href="{{ route('transfers.show', $transfer->id) }}">Detail</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">Žádné převody.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{ $transfers->links() }}
@endsection
