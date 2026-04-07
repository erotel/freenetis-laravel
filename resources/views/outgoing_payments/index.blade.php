@extends('layouts.app')

@section('title', 'Odchozí platby')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('outgoing_payments.index') }}">Odchozí platby</a>
    </div>
@endsection

@section('content')
    <h2>Odchozí platby</h2>

    @if($canEdit && count($exportBankAccounts) > 0)
        <div style="margin-bottom:15px;">
            <strong>Export schválených plateb:</strong><br>
            <small style="color:#666;">Pokud není nastaven FIO token, stáhne se XML soubor; pokud je nastaven, platby se odešlou přímo do FIO.</small>
            <div style="margin-top:8px;">
                @foreach($exportBankAccounts as $ba)
                    <form method="POST" action="{{ route('outgoing_payments.export', $ba['id']) }}"
                          style="display:inline-block; margin-right:8px; margin-bottom:4px;">
                        @csrf
                        <button type="submit"
                                class="btn"
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

    {{-- Status filter tabs --}}
    <div style="margin-bottom:1em;">
        <a href="{{ route('outgoing_payments.index') }}"
           style="{{ !$currentStatus ? 'font-weight:bold;' : '' }}">Všechny</a>
        @foreach($statusLabels as $key => $label)
            &nbsp;|&nbsp;
            <a href="{{ route('outgoing_payments.index', ['status' => $key]) }}"
               style="color:{{ $statusColors[$key] }};{{ $currentStatus === $key ? 'font-weight:bold;' : '' }}">
                {{ $label }}
            </a>
        @endforeach
    </div>

    <table class="extended" cellspacing="0">
        <thead>
            <tr>
                <th>ID</th>
                <th>Z účtu</th>
                <th>Vytvořeno</th>
                <th>Cílový účet</th>
                <th>Příjemce</th>
                <th style="text-align:right;">Částka</th>
                <th>VS</th>
                <th>Zpráva</th>
                <th>Důvod</th>
                <th>Stav</th>
                <th>Akce</th>
            </tr>
        </thead>
        <tbody>
            @forelse($payments as $payment)
                <tr>
                    <td>
                        <a href="{{ route('outgoing_payments.show', $payment->id) }}">{{ $payment->id }}</a>
                    </td>
                    <td>{{ $payment->bankAccount?->name }} ({{ $payment->bankAccount?->full_account_number }})</td>
                    <td>{{ $payment->created_at->format('d.m.Y') }}</td>
                    <td>{{ $payment->target_account }}</td>
                    <td>{{ $payment->target_name ?: '—' }}</td>
                    <td style="text-align:right;">{{ number_format($payment->amount, 2, ',', ' ') }} {{ $payment->currency }}</td>
                    <td>{{ $payment->variable_symbol ?: '—' }}</td>
                    <td>{{ $payment->message ?: '—' }}</td>
                    <td>{{ $reasonLabels[$payment->reason] ?? $payment->reason }}</td>
                    <td>
                        <span style="color:{{ $statusColors[$payment->status] ?? 'inherit' }}">
                            {{ $statusLabels[$payment->status] ?? $payment->status }}
                        </span>
                    </td>
                    <td class="action" style="white-space:nowrap;">
                        @if($canEdit && $payment->status === 'draft')
                            <form method="POST" action="{{ route('outgoing_payments.approve', $payment->id) }}"
                                  style="display:inline;">
                                @csrf
                                <button type="submit" title="Schválit"
                                        style="background:none;border:none;cursor:pointer;color:#0a0;padding:0;">
                                    ✓ Schválit
                                </button>
                            </form>
                            &nbsp;
                        @endif
                        @if($canEdit && in_array($payment->status, ['draft', 'approved']))
                            <form method="POST" action="{{ route('outgoing_payments.cancel', $payment->id) }}"
                                  style="display:inline;"
                                  onsubmit="return confirm('Opravdu zrušit platbu #{{ $payment->id }}?');">
                                @csrf
                                <button type="submit" title="Zrušit"
                                        style="background:none;border:none;cursor:pointer;color:#c00;padding:0;">
                                    ✕ Zrušit
                                </button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="10">Žádné odchozí platby.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="pagination-wrap">{{ $payments->links() }}</div>
@endsection
