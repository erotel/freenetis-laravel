@extends('layouts.app')
@section('title', 'Odchozí platby')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs"><a href="{{ route('outgoing_payments.index') }}">Odchozí platby</a></div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Odchozí platby</h2></div>
<div class="m-subtitle">Celkem: {{ $payments->total() }} záznamů</div>

@if($canEdit && count($exportBankAccounts) > 0)
<div class="m-card" style="margin-bottom:16px;padding:14px 1.25rem">
    <div class="m-card-title">Export schválených plateb</div>
    <div class="m-form-hint" style="margin-bottom:8px">Pokud není nastaven FIO token, stáhne se XML soubor; pokud je nastaven, platby se odešlou přímo do FIO.</div>
    <div style="display:flex;flex-wrap:wrap;gap:8px">
        @foreach($exportBankAccounts as $ba)
        <form method="POST" action="{{ route('outgoing_payments.export', $ba['id']) }}" style="display:inline">
            @csrf
            <button class="m-btn m-btn-primary" type="submit"
                    onclick="return confirm('Exportovat schválené platby na účet {{ addslashes($ba['name']) }}?')">
                @if($ba['has_token'])
                    Odeslat do FIO ({{ $ba['name'] }} – {{ $ba['full_nr'] }})
                @else
                    Stáhnout XML ({{ $ba['name'] }} – {{ $ba['full_nr'] }})
                @endif
            </button>
        </form>
        @endforeach
    </div>
</div>
@endif

<div style="display:flex;gap:4px;margin-bottom:16px;flex-wrap:wrap">
    <a class="m-btn @if(!$currentStatus) m-btn-primary @endif" href="{{ route('outgoing_payments.index') }}">Všechny</a>
    @foreach($statusLabels as $key => $label)
    <a class="m-btn @if($currentStatus === $key) m-btn-primary @endif"
       href="{{ route('outgoing_payments.index', ['status' => $key]) }}"
       style="color:{{ $statusColors[$key] }}">{{ $label }}</a>
    @endforeach
</div>

<div style="margin-bottom:8px">{{ $payments->links() }}</div>

<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px">ID</th>
            <th>Z účtu</th>
            <th style="width:90px">Vytvořeno</th>
            <th>Cílový účet</th>
            <th>Příjemce</th>
            <th style="width:110px;text-align:right">Částka</th>
            <th style="width:100px">VS</th>
            <th>Zpráva</th>
            <th style="width:100px">Důvod</th>
            <th style="width:90px">Stav</th>
            <th style="width:110px">Akce</th>
        </tr>
    </thead>
    <tbody>
        @forelse($payments as $payment)
        <tr>
            <td><a class="m-link" href="{{ route('outgoing_payments.show', $payment->id) }}">{{ $payment->id }}</a></td>
            <td style="font-size:12px">{{ $payment->bankAccount?->name }}</td>
            <td style="font-size:12px">{{ $payment->created_at->format('d.m.Y') }}</td>
            <td style="font-family:monospace;font-size:12px">{{ $payment->target_account }}</td>
            <td style="font-size:12px">{{ $payment->target_name ?: '—' }}</td>
            <td style="text-align:right;font-family:monospace;font-size:12px">{{ number_format($payment->amount, 2, ',', ' ') }} {{ $payment->currency }}</td>
            <td style="font-family:monospace;font-size:12px">{{ $payment->variable_symbol ?: '—' }}</td>
            <td style="font-size:12px">{{ $payment->message ?: '—' }}</td>
            <td style="font-size:12px">{{ $reasonLabels[$payment->reason] ?? $payment->reason }}</td>
            <td>
                <span style="color:{{ $statusColors[$payment->status] ?? 'inherit' }};font-size:12px">
                    {{ $statusLabels[$payment->status] ?? $payment->status }}
                </span>
            </td>
            <td>
                <div style="display:flex;gap:6px;flex-wrap:wrap">
                    @if($canEdit && $payment->status === 'draft')
                    <form method="POST" action="{{ route('outgoing_payments.approve', $payment->id) }}" style="display:inline">
                        @csrf
                        <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;font-size:12px;color:#27ae60">✓ Schválit</button>
                    </form>
                    @endif
                    @if($canEdit && in_array($payment->status, ['draft', 'approved']))
                    <form method="POST" action="{{ route('outgoing_payments.cancel', $payment->id) }}" style="display:inline"
                          onsubmit="return confirm('Opravdu zrušit platbu #{{ $payment->id }}?')">
                        @csrf
                        <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;font-size:12px;color:#c0392b">✕ Zrušit</button>
                    </form>
                    @endif
                </div>
            </td>
        </tr>
        @empty
        <tr><td colspan="11" style="text-align:center;color:#aaa;padding:2rem">Žádné odchozí platby.</td></tr>
        @endforelse
    </tbody>
</table>
</div>

<div style="margin-top:12px">{{ $payments->links() }}</div>
</div>
@endsection
