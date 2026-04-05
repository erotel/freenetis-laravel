@extends('layouts.app')

@section('title', 'Odchozí platba #' . $payment->id)

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('outgoing_payments.index') }}">Odchozí platby</a> &raquo;
        Platba #{{ $payment->id }}
    </div>
@endsection

@section('content')
    <h2>Odchozí platba #{{ $payment->id }}</h2>

    <div style="margin-bottom:1em;">
        @if($canEdit && $payment->status === 'draft')
            <form method="POST" action="{{ route('outgoing_payments.approve', $payment->id) }}"
                  style="display:inline;">
                @csrf
                <button type="submit">✓ Schválit</button>
            </form>
            &nbsp;
        @endif
        @if($canEdit && in_array($payment->status, ['draft', 'approved']))
            <form method="POST" action="{{ route('outgoing_payments.cancel', $payment->id) }}"
                  style="display:inline;"
                  onsubmit="return confirm('Opravdu zrušit tuto platbu?');">
                @csrf
                <button type="submit">✕ Zrušit</button>
            </form>
        @endif
    </div>

    <table class="extended" cellspacing="0">
        <thead>
            <tr><th colspan="2">Detail odchozí platby</th></tr>
        </thead>
        <tbody>
            <tr><th>ID</th><td>{{ $payment->id }}</td></tr>
            <tr>
                <th>Stav</th>
                <td style="color:{{ $statusColors[$payment->status] ?? 'inherit' }}">
                    <strong>{{ $statusLabels[$payment->status] ?? $payment->status }}</strong>
                </td>
            </tr>
            <tr><th>Bankovní účet</th><td>
                @if($payment->bankAccount)
                    <a href="{{ route('bank_accounts.show', $payment->bank_account_id) }}">
                        {{ $payment->bankAccount->name }}
                    </a>
                @else
                    —
                @endif
            </td></tr>
            <tr><th>Cílový účet</th><td>{{ $payment->target_account }}</td></tr>
            <tr><th>Příjemce</th><td>{{ $payment->target_name ?: '—' }}</td></tr>
            <tr><th>Částka</th><td>{{ number_format($payment->amount, 2, ',', ' ') }} {{ $payment->currency }}</td></tr>
            <tr><th>Variabilní symbol</th><td>{{ $payment->variable_symbol ?: '—' }}</td></tr>
            <tr><th>Zpráva</th><td>{{ $payment->message ?: '—' }}</td></tr>
            <tr><th>Důvod</th><td>{{ $reasonLabels[$payment->reason] ?? $payment->reason }}</td></tr>
            <tr><th>Vytvořeno</th><td>{{ $payment->created_at->format('d.m.Y H:i') }}</td></tr>
            <tr><th>Vytvořil</th><td>{{ $payment->createdBy?->name }} {{ $payment->createdBy?->surname }}</td></tr>
            @if($payment->approved_by)
                <tr><th>Schválil</th><td>{{ $payment->approvedBy?->name }} {{ $payment->approvedBy?->surname }}</td></tr>
            @endif
            @if($payment->exported_at)
                <tr><th>Exportováno</th><td>{{ \Carbon\Carbon::parse($payment->exported_at)->format('d.m.Y H:i') }}</td></tr>
            @endif
            @if($payment->paid_at)
                <tr><th>Zaplaceno</th><td>{{ \Carbon\Carbon::parse($payment->paid_at)->format('d.m.Y H:i') }}</td></tr>
            @endif
            @if($payment->transfer_id)
                <tr><th>Systémový převod</th>
                    <td><a href="{{ route('transfers.show', $payment->transfer_id) }}">#{{ $payment->transfer_id }}</a></td>
                </tr>
            @endif
        </tbody>
    </table>
@endsection
