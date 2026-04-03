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

    <div style="margin-bottom:1em;">
        <a href="{{ route('accounts.show', $account->id) }}">&larr; Zpět na účet</a>
    </div>

    <table class="extended" cellspacing="0" style="float:left; margin-right:20px;">
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

    <div style="clear:both;"></div>

    @php
        $nextDir = fn(string $col) => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
        $arrow   = fn(string $col) => $sort === $col ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
        $sortUrl = fn(string $col) => request()->fullUrlWithQuery(['sort' => $col, 'dir' => $nextDir($col)]);
    @endphp

    <table class="extended" cellspacing="0">
        <thead>
            <tr>
                <th><a href="{{ $sortUrl('id') }}">ID{{ $arrow('id') }}</a></th>
                <th><a href="{{ $sortUrl('datetime') }}">Datum{{ $arrow('datetime') }}</a></th>
                <th>Popis</th>
                <th>Protistrana</th>
                <th style="text-align:right;"><a href="{{ $sortUrl('amount') }}">Částka{{ $arrow('amount') }}</a></th>
                <th style="text-align:right;">Kumulativní zůstatek</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                @php
                    $transfer = $row['transfer'];
                    $signed   = $row['signed'];
                    $running  = $row['running_balance'];
                    $amountColor  = $signed >= 0 ? 'green' : 'red';
                    $runningColor = $running >= 0 ? 'green' : 'red';

                    // Counterpart account
                    $accountId = $account->id;
                    if ($transfer->origin_id === $accountId) {
                        $counterpart = $transfer->destination;
                    } else {
                        $counterpart = $transfer->origin;
                    }
                @endphp
                <tr>
                    <td>{{ $transfer->id }}</td>
                    <td>{{ $transfer->datetime?->format('d.m.Y') }}</td>
                    <td>{{ $transfer->text }}</td>
                    <td>
                        @if($counterpart)
                            <a href="{{ route('accounts.show', $counterpart->id) }}">{{ $counterpart->name }}</a>
                        @else
                            —
                        @endif
                    </td>
                    <td style="text-align:right; color:{{ $amountColor }};">
                        {{ ($signed >= 0 ? '+' : '') . number_format($signed, 2, ',', ' ') }} Kč
                    </td>
                    <td style="text-align:right; color:{{ $runningColor }};">
                        {{ number_format($running, 2, ',', ' ') }} Kč
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">Žádné převody.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection
