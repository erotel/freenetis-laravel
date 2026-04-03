@extends('layouts.app')

@section('title', 'Převod #' . $transfer->id)

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('transfers.index') }}">Převody</a> &raquo;
        #{{ $transfer->id }}
    </div>
@endsection

@section('content')
    <h2>Převod #{{ $transfer->id }}</h2>

    <div style="margin-bottom:1em;">
        <a href="{{ route('transfers.index') }}">&larr; Zpět na deník</a>
    </div>

    <table class="extended" cellspacing="0">
        <thead>
            <tr><th colspan="2">Detail převodu</th></tr>
        </thead>
        <tbody>
            <tr>
                <th>ID</th>
                <td>{{ $transfer->id }}</td>
            </tr>
            <tr>
                <th>Datum efektivní</th>
                <td>{{ $transfer->datetime?->format('d.m.Y H:i') }}</td>
            </tr>
            <tr>
                <th>Datum vytvoření</th>
                <td>{{ $transfer->creation_datetime?->format('d.m.Y H:i') }}</td>
            </tr>
            <tr>
                <th>Popis</th>
                <td>{{ $transfer->text ?? '—' }}</td>
            </tr>
            <tr>
                <th>Typ</th>
                <td>{{ \App\Models\Transfer::typeLabel($transfer->type ?? 0) }}</td>
            </tr>
            <tr>
                <th>Částka</th>
                <td>{{ number_format($transfer->amount, 2, ',', ' ') }} Kč</td>
            </tr>
            <tr>
                <th>Z účtu</th>
                <td>
                    @if($transfer->origin)
                        <a href="{{ route('accounts.show', $transfer->origin_id) }}">{{ $transfer->origin->name }}</a>
                    @else
                        —
                    @endif
                </td>
            </tr>
            <tr>
                <th>Na účet</th>
                <td>
                    @if($transfer->destination)
                        <a href="{{ route('accounts.show', $transfer->destination_id) }}">{{ $transfer->destination->name }}</a>
                    @else
                        —
                    @endif
                </td>
            </tr>
            <tr>
                <th>Člen</th>
                <td>
                    @if($transfer->member)
                        <a href="{{ route('members.show', $transfer->member_id) }}">{{ $transfer->member->name }}</a>
                    @else
                        —
                    @endif
                </td>
            </tr>
            <tr>
                <th>Vytvořil</th>
                <td>
                    @if($transfer->user)
                        <a href="{{ route('users.show', $transfer->user_id) }}">{{ $transfer->user->name }}</a>
                    @else
                        —
                    @endif
                </td>
            </tr>
            @if($transfer->previousTransfer)
                <tr>
                    <th>Předchozí převod</th>
                    <td>
                        <a href="{{ route('transfers.show', $transfer->previousTransfer->id) }}">#{{ $transfer->previousTransfer->id }}</a>
                        — {{ $transfer->previousTransfer->text }}
                    </td>
                </tr>
            @endif
        </tbody>
    </table>
@endsection
