@extends('layouts.app')
@section('title', 'Odchozí platba #' . $payment->id)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('outgoing_payments.index') }}">Odchozí platby</a> &raquo; Platba #{{ $payment->id }}
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Odchozí platba #{{ $payment->id }}</h2></div>

<div class="m-actions">
    @if($canEdit && $payment->status === 'draft')
    <form method="POST" action="{{ route('outgoing_payments.approve', $payment->id) }}" style="display:inline">
        @csrf
        <button class="m-btn m-btn-success" type="submit">✓ Schválit</button>
    </form>
    @endif
    @if($canEdit && in_array($payment->status, ['draft', 'approved']))
    <form method="POST" action="{{ route('outgoing_payments.cancel', $payment->id) }}" style="display:inline"
          onsubmit="return confirm('Opravdu zrušit tuto platbu?')">
        @csrf
        <button class="m-btn m-btn-danger" type="submit">✕ Zrušit</button>
    </form>
    @endif
</div>

<div class="m-card" style="max-width:480px">
    <div class="m-card-title">Detail odchozí platby</div>
    <div class="m-field"><span class="m-field-label">ID</span><span class="m-field-value">{{ $payment->id }}</span></div>
    <div class="m-field">
        <span class="m-field-label">Stav</span>
        <span class="m-field-value" style="color:{{ $statusColors[$payment->status] ?? 'inherit' }};font-weight:500">
            {{ $statusLabels[$payment->status] ?? $payment->status }}
        </span>
    </div>
    <div class="m-field">
        <span class="m-field-label">Bankovní účet</span>
        <span class="m-field-value">
            @if($payment->bankAccount) <a href="{{ route('bank_accounts.show', $payment->bank_account_id) }}">{{ $payment->bankAccount->name }}</a>
            @else — @endif
        </span>
    </div>
    <div class="m-field"><span class="m-field-label">Cílový účet</span><span class="m-field-value" style="font-family:monospace">{{ $payment->target_account }}</span></div>
    <div class="m-field"><span class="m-field-label">Příjemce</span><span class="m-field-value">{{ $payment->target_name ?: '—' }}</span></div>
    <div class="m-field"><span class="m-field-label">Částka</span><span class="m-field-value" style="font-family:monospace">{{ number_format($payment->amount, 2, ',', ' ') }} {{ $payment->currency }}</span></div>
    <div class="m-field"><span class="m-field-label">Variabilní symbol</span><span class="m-field-value" style="font-family:monospace">{{ $payment->variable_symbol ?: '—' }}</span></div>
    <div class="m-field"><span class="m-field-label">Zpráva</span><span class="m-field-value">{{ $payment->message ?: '—' }}</span></div>
    <div class="m-field"><span class="m-field-label">Důvod</span><span class="m-field-value">{{ $reasonLabels[$payment->reason] ?? $payment->reason }}</span></div>
    <div class="m-field"><span class="m-field-label">Vytvořeno</span><span class="m-field-value">{{ $payment->created_at->format('d.m.Y H:i') }}</span></div>
    <div class="m-field"><span class="m-field-label">Vytvořil</span><span class="m-field-value">{{ $payment->createdBy?->name }} {{ $payment->createdBy?->surname }}</span></div>
    @if($payment->approved_by)
    <div class="m-field"><span class="m-field-label">Schválil</span><span class="m-field-value">{{ $payment->approvedBy?->name }} {{ $payment->approvedBy?->surname }}</span></div>
    @endif
    @if($payment->exported_at)
    <div class="m-field"><span class="m-field-label">Exportováno</span><span class="m-field-value">{{ \Carbon\Carbon::parse($payment->exported_at)->format('d.m.Y H:i') }}</span></div>
    @endif
    @if($payment->paid_at)
    <div class="m-field"><span class="m-field-label">Zaplaceno</span><span class="m-field-value">{{ \Carbon\Carbon::parse($payment->paid_at)->format('d.m.Y H:i') }}</span></div>
    @endif
    @if($payment->transfer_id)
    <div class="m-field">
        <span class="m-field-label">Systémový převod</span>
        <span class="m-field-value"><a href="{{ route('transfers.show', $payment->transfer_id) }}">#{{ $payment->transfer_id }}</a></span>
    </div>
    @endif
</div>
</div>
@endsection
