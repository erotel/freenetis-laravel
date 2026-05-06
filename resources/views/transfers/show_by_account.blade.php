@extends('layouts.app')
@section('title', 'Převody účtu: ' . $account->name)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('transfers.index') }}">Převody</a> &raquo;
    <a href="{{ route('accounts.show', $account->id) }}">{{ $account->name }}</a> &raquo;
    Převody účtu
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Převody účtu: {{ $account->name }}</h2></div>

<div class="m-actions">
    <a class="m-btn" href="{{ route('accounts.show', $account->id) }}">&larr; Zpět na účet</a>
</div>

<div class="m-metrics">
    <div class="m-metric">
        <div class="m-metric-label">Zůstatek</div>
        <div class="m-metric-value {{ $account->balance > 0 ? 'green' : ($account->balance < 0 ? 'red' : '') }}">
            {{ number_format($account->balance, 2, ',', ' ') }} Kč
        </div>
    </div>
    <div class="m-metric">
        <div class="m-metric-label">Typ</div>
        <div class="m-metric-value sm">{{ $account->accountAttribute?->name ?? '—' }}</div>
    </div>
    <div class="m-metric">
        <div class="m-metric-label">Člen</div>
        <div class="m-metric-value sm">
            @if($account->member)
                <a href="{{ route('members.show', $account->member_id) }}" class="m-link">{{ $account->member->name }}</a>
            @else —
            @endif
        </div>
    </div>
</div>

<div style="margin-bottom:8px">{{ $transfers->links() }}</div>

<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px">ID</th>
            <th>Protiúčet</th>
            <th style="width:90px">Datum</th>
            <th style="width:120px;text-align:right">Částka</th>
            <th>Text</th>
            <th style="width:60px">Akce</th>
        </tr>
    </thead>
    <tbody>
        @forelse($transfers as $transfer)
        @php
            $isOutgoing  = $transfer->origin_id === $accountId;
            $counterpart = $isOutgoing ? $transfer->destination : $transfer->origin;
            $signed      = $isOutgoing ? -$transfer->amount : $transfer->amount;
        @endphp
        <tr>
            <td>{{ $transfer->id }}</td>
            <td>
                @if($counterpart) <a class="m-link" href="{{ route('accounts.show', $counterpart->id) }}">{{ $counterpart->name }}</a>
                @else — @endif
            </td>
            <td style="font-size:14px;color:#888">{{ $transfer->datetime?->format('d.m.Y') }}</td>
            <td style="text-align:right;font-family:monospace;font-size:14px;color:{{ $signed >= 0 ? '#27ae60' : '#c0392b' }}">
                {{ ($signed >= 0 ? '+' : '') . number_format($signed, 2, ',', ' ') }} Kč
            </td>
            <td>{{ $transfer->text }}</td>
            <td><a class="m-link-sm" href="{{ route('transfers.show', $transfer->id) }}">Detail</a></td>
        </tr>
        @empty
        <tr><td colspan="6" style="text-align:center;color:#aaa;padding:2rem">Žádné převody.</td></tr>
        @endforelse
    </tbody>
</table>
</div>

<div style="margin-top:12px">{{ $transfers->links() }}</div>
</div>
@endsection
