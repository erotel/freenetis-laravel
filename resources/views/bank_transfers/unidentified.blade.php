@extends('layouts.app')
@section('title', 'Neidentifikované převody')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('bank_accounts.index') }}">Bankovní účty</a> &raquo; Neidentifikované převody
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Neidentifikované převody</h2></div>
<div class="m-subtitle">Tyto bankovní převody nebyly spárovány s žádným členem (chybí VS nebo přišly na nesprávný účet).</div>

<div style="margin-bottom:8px">{{ $transfers->links() }}</div>

<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px">ID</th>
            <th>Bankovní účet</th>
            <th style="width:100px">Datum</th>
            <th>Č. protiúčtu</th>
            <th>Název účtu</th>
            <th style="width:110px">VS</th>
            <th style="width:110px;text-align:right">Částka</th>
            <th>Komentář</th>
            <th style="width:70px">Akce</th>
        </tr>
    </thead>
    <tbody>
        @forelse($transfers as $bt)
        @php
            $mainAccount = $bt->bankStatement?->bankAccount;
            $isIncoming  = $bt->destination_id == $mainAccount?->id;
            $counterpart = $isIncoming ? $bt->originAccount : $bt->destinationAccount;
            $amount      = $bt->transfer?->amount;
        @endphp
        <tr>
            <td>{{ $bt->id }}</td>
            <td>
                @if($mainAccount) <a class="m-link" href="{{ route('bank_accounts.show', $mainAccount->id) }}">{{ $mainAccount->name }}</a>
                @else — @endif
            </td>
            <td style="font-size:12px">{{ $bt->bankStatement?->from?->format('d.m.Y') ?? '—' }}</td>
            <td style="font-family:monospace;font-size:12px">{{ $counterpart?->full_account_number ?? '—' }}</td>
            <td style="font-size:12px">{{ $counterpart?->name ?? '—' }}</td>
            <td style="font-family:monospace;font-size:12px">
                @if($bt->variable_symbol)
                    {{ $bt->variable_symbol }}
                @else
                    <span class="m-tag m-tag-red">chybí</span>
                @endif
            </td>
            <td style="text-align:right;font-family:monospace;font-size:12px">
                {{ $amount !== null ? number_format($amount, 2, ',', ' ') . ' Kč' : '—' }}
            </td>
            <td style="font-size:12px">{{ $bt->comment ?: '—' }}</td>
            <td><a class="m-link-sm" href="{{ route('bank-transfers.refund.form', $bt->id) }}">↩ Vrátit</a></td>
        </tr>
        @empty
        <tr><td colspan="9" style="text-align:center;color:#aaa;padding:2rem">Žádné neidentifikované převody.</td></tr>
        @endforelse
    </tbody>
</table>
</div>

<div style="margin-top:12px">{{ $transfers->links() }}</div>
</div>
@endsection
