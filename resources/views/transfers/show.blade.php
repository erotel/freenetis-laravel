@extends('layouts.app')
@section('title', 'Převod #' . $transfer->id)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('transfers.index') }}">Převody</a> &raquo; #{{ $transfer->id }}
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Převod #{{ $transfer->id }}</h2></div>

<div class="m-actions">
    <a class="m-btn" href="{{ route('transfers.index') }}">&larr; Zpět na deník</a>
    @if($transfer->bankTransfer?->bank_statement_id)
    <span class="m-btn" style="cursor:default;background:#eee;color:#555">Bankovní výpis #{{ $transfer->bankTransfer->bank_statement_id }}</span>
    @endif
</div>

@php
    // Renderovací pomocník pro samostatný "převod" blok (hlavní, předchozí, závislé).
    $renderTransferCard = function ($t, string $title) {
        return [$t, $title];
    };
@endphp

<div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start">

    {{-- ───── Hlavní převod ───── --}}
    <div class="m-card" style="flex:1 1 460px;max-width:560px">
        <div class="m-card-title">Detail převodu</div>
        <div class="m-field"><span class="m-field-label">ID</span><span class="m-field-value">{{ $transfer->id }}</span></div>
        <div class="m-field"><span class="m-field-label">Datum efektivní</span><span class="m-field-value">{{ $transfer->datetime?->format('d.m.Y H:i') }}</span></div>
        <div class="m-field"><span class="m-field-label">Datum vytvoření</span><span class="m-field-value">{{ $transfer->creation_datetime?->format('d.m.Y H:i') }}</span></div>
        <div class="m-field"><span class="m-field-label">Popis</span><span class="m-field-value">{{ $transfer->text ?? '—' }}</span></div>
        <div class="m-field"><span class="m-field-label">Typ</span><span class="m-field-value">{{ \App\Models\Transfer::typeLabel($transfer->type ?? 0) }}</span></div>
        <div class="m-field">
            <span class="m-field-label">Částka</span>
            <span class="m-field-value" style="font-family:monospace">{{ number_format($transfer->amount, 2, ',', ' ') }} Kč</span>
        </div>
        <div class="m-field">
            <span class="m-field-label">Z účtu</span>
            <span class="m-field-value">
                @if($transfer->origin) <a href="{{ route('transfers.by_account', $transfer->origin_id) }}">{{ $transfer->origin->name }}</a>
                @else — @endif
            </span>
        </div>
        <div class="m-field">
            <span class="m-field-label">Na účet</span>
            <span class="m-field-value">
                @if($transfer->destination) <a href="{{ route('transfers.by_account', $transfer->destination_id) }}">{{ $transfer->destination->name }}</a>
                @else — @endif
            </span>
        </div>
        <div class="m-field">
            <span class="m-field-label">Člen</span>
            <span class="m-field-value">
                @if($transfer->member) <a href="{{ route('members.show', $transfer->member_id) }}">{{ $transfer->member->name }}</a>
                @else — @endif
            </span>
        </div>
        <div class="m-field">
            <span class="m-field-label">Vytvořil</span>
            <span class="m-field-value">
                @if($transfer->user) <a href="{{ route('users.show', $transfer->user_id) }}">{{ $transfer->user->name }}</a>
                @else — @endif
            </span>
        </div>
    </div>

    {{-- ───── Bankovní převod (pokud existuje) ───── --}}
    @if($transfer->bankTransfer)
    @php $bt = $transfer->bankTransfer; @endphp
    <div class="m-card" style="flex:1 1 360px;max-width:480px">
        <div class="m-card-title">Bankovní převod</div>
        <div class="m-field"><span class="m-field-label">ID</span><span class="m-field-value">{{ $bt->id }}</span></div>
        <div class="m-field"><span class="m-field-label">Transakční kód</span><span class="m-field-value" style="font-family:monospace">{{ $bt->transaction_code ?? '—' }}</span></div>
        <div class="m-field"><span class="m-field-label">Konstantní symbol</span><span class="m-field-value" style="font-family:monospace">{{ $bt->constant_symbol ?? '—' }}</span></div>
        <div class="m-field"><span class="m-field-label">Variabilní symbol</span><span class="m-field-value" style="font-family:monospace">{{ $bt->variable_symbol ?? '—' }}</span></div>
        <div class="m-field"><span class="m-field-label">Specifický symbol</span><span class="m-field-value" style="font-family:monospace">{{ $bt->specific_symbol ?? '—' }}</span></div>
        @if($bt->comment)
        <div class="m-field"><span class="m-field-label">Poznámka</span><span class="m-field-value">{{ $bt->comment }}</span></div>
        @endif

        <div style="margin-top:10px;padding-top:8px;border-top:1px solid #eee">
            <div class="m-field" style="font-weight:600;color:#555"><span class="m-field-label">Zdrojový bank. účet</span></div>
            <div class="m-field"><span class="m-field-label" style="padding-left:1em">Název</span><span class="m-field-value">{{ $bt->originAccount?->name ?? '—' }}</span></div>
            <div class="m-field"><span class="m-field-label" style="padding-left:1em">Číslo</span><span class="m-field-value" style="font-family:monospace">{{ $bt->originAccount?->account_nr ?? '—' }}{{ $bt->originAccount?->bank_nr ? '/' . $bt->originAccount->bank_nr : '' }}</span></div>
        </div>
        <div style="margin-top:6px;padding-top:8px;border-top:1px solid #eee">
            <div class="m-field" style="font-weight:600;color:#555"><span class="m-field-label">Cílový bank. účet</span></div>
            <div class="m-field"><span class="m-field-label" style="padding-left:1em">Název</span><span class="m-field-value">{{ $bt->destinationAccount?->name ?? '—' }}</span></div>
            <div class="m-field"><span class="m-field-label" style="padding-left:1em">Číslo</span><span class="m-field-value" style="font-family:monospace">{{ $bt->destinationAccount?->account_nr ?? '—' }}{{ $bt->destinationAccount?->bank_nr ? '/' . $bt->destinationAccount->bank_nr : '' }}</span></div>
        </div>
    </div>
    @endif

</div>

{{-- ───── Předchozí převod (inline, ne jen link) ───── --}}
@if($transfer->previousTransfer)
@php $pt = $transfer->previousTransfer; @endphp
<div class="m-card" style="max-width:560px;margin-top:16px;border-left:4px solid #3b82f6">
    <div class="m-card-title">
        Předchozí převod
        <a class="m-link-sm" href="{{ route('transfers.show', $pt->id) }}" style="margin-left:8px;font-weight:normal">→ otevřít detail #{{ $pt->id }}</a>
    </div>
    <div class="m-field"><span class="m-field-label">ID</span><span class="m-field-value">{{ $pt->id }}</span></div>
    <div class="m-field"><span class="m-field-label">Datum efektivní</span><span class="m-field-value">{{ $pt->datetime?->format('d.m.Y H:i') }}</span></div>
    <div class="m-field"><span class="m-field-label">Datum vytvoření</span><span class="m-field-value">{{ $pt->creation_datetime?->format('d.m.Y H:i') }}</span></div>
    <div class="m-field"><span class="m-field-label">Popis</span><span class="m-field-value">{{ $pt->text ?? '—' }}</span></div>
    <div class="m-field"><span class="m-field-label">Typ</span><span class="m-field-value">{{ \App\Models\Transfer::typeLabel($pt->type ?? 0) }}</span></div>
    <div class="m-field">
        <span class="m-field-label">Částka</span>
        <span class="m-field-value" style="font-family:monospace">{{ number_format($pt->amount, 2, ',', ' ') }} Kč</span>
    </div>
    <div class="m-field">
        <span class="m-field-label">Z účtu</span>
        <span class="m-field-value">
            @if($pt->origin) <a href="{{ route('transfers.by_account', $pt->origin_id) }}">{{ $pt->origin->name }}</a>
            @else — @endif
        </span>
    </div>
    <div class="m-field">
        <span class="m-field-label">Na účet</span>
        <span class="m-field-value">
            @if($pt->destination) <a href="{{ route('transfers.by_account', $pt->destination_id) }}">{{ $pt->destination->name }}</a>
            @else — @endif
        </span>
    </div>
    <div class="m-field">
        <span class="m-field-label">Člen</span>
        <span class="m-field-value">
            @if($pt->member) <a href="{{ route('members.show', $pt->member_id) }}">{{ $pt->member->name }}</a>
            @else — @endif
        </span>
    </div>
    <div class="m-field">
        <span class="m-field-label">Vytvořil</span>
        <span class="m-field-value">
            @if($pt->user) <a href="{{ route('users.show', $pt->user_id) }}">{{ $pt->user->name }}</a>
            @else — @endif
        </span>
    </div>
    @if($pt->bankTransfer)
    <div class="m-field" style="margin-top:8px;padding-top:8px;border-top:1px solid #eee">
        <span class="m-field-label">Bank. převod</span>
        <span class="m-field-value">
            #{{ $pt->bankTransfer->id }} — VS: {{ $pt->bankTransfer->variable_symbol ?? '—' }}, transakční kód: {{ $pt->bankTransfer->transaction_code ?? '—' }}
        </span>
    </div>
    @endif
</div>
@endif

{{-- ───── Závislé převody (vratky/opravy, které referují tento jako previous) ───── --}}
@if($transfer->dependentTransfers->count() > 0)
<div class="m-card" style="max-width:560px;margin-top:16px">
    <div class="m-card-title">Závislé převody</div>
    <table class="m-table" style="margin-bottom:0;font-size:14px">
        <thead><tr><th>ID</th><th>Datum</th><th>Popis</th><th style="text-align:right">Částka</th></tr></thead>
        <tbody>
            @foreach($transfer->dependentTransfers as $dep)
            <tr>
                <td><a class="m-link-sm" href="{{ route('transfers.show', $dep->id) }}">#{{ $dep->id }}</a></td>
                <td>{{ $dep->datetime?->format('d.m.Y') }}</td>
                <td>{{ $dep->text ?? '—' }}</td>
                <td style="text-align:right;font-family:monospace">{{ number_format($dep->amount, 2, ',', ' ') }} Kč</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif

</div>
@endsection
