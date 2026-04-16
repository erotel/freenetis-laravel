@extends('layouts.app')
@section('title', 'Převody: ' . $account->name)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('bank_accounts.index') }}">Bankovní účty</a> &raquo;
    <a href="{{ route('bank_accounts.show', $account->id) }}">{{ $account->name }}</a> &raquo;
    Převody
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Převody na bankovním účtu: {{ $account->name }}</h2></div>

<div class="m-actions">
    <a class="m-btn" href="{{ route('bank_accounts.show', $account->id) }}">&larr; Zpět na účet</a>
</div>

<div class="m-card" style="max-width:360px;margin-bottom:16px">
    <div class="m-field"><span class="m-field-label">Číslo účtu</span><span class="m-field-value" style="font-family:monospace">{{ $account->full_account_number }}</span></div>
    <div class="m-field"><span class="m-field-label">IBAN</span><span class="m-field-value" style="font-family:monospace">{{ $account->IBAN ?: '—' }}</span></div>
</div>

<div style="margin-bottom:8px">{{ $transfers->links() }}</div>

<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px">ID</th>
            <th style="width:100px">Výpis</th>
            <th>Protiúčet</th>
            <th style="width:110px">VS</th>
            <th style="width:80px">KS</th>
            <th>Text</th>
            <th style="width:100px">Stav</th>
            <th style="width:60px">Akce</th>
        </tr>
    </thead>
    <tbody>
        @forelse($transfers as $bt)
        @php
            $isIncoming = $bt->destination_id == $account->id;
            $counterpart = $isIncoming ? $bt->originAccount : $bt->destinationAccount;
        @endphp
        <tr>
            <td>{{ $bt->id }}</td>
            <td style="font-size:12px">{{ $bt->bankStatement?->from?->format('d.m.Y') ?? '—' }}</td>
            <td style="font-family:monospace;font-size:12px">
                @if($counterpart)
                    <a class="m-link" href="{{ route('bank_accounts.show', $counterpart->id) }}">{{ $counterpart->full_account_number }}</a>
                @else —
                @endif
            </td>
            <td style="font-family:monospace;font-size:12px">{{ $bt->variable_symbol ?: '—' }}</td>
            <td style="font-size:12px">{{ $bt->constant_symbol ?: '—' }}</td>
            <td style="font-size:12px">{{ $bt->comment ?: '—' }}</td>
            <td>
                @if($bt->transfer_id)
                    <span class="m-tag m-tag-green">Spárováno</span>
                @else
                    <span class="m-tag m-tag-red">Nespárováno</span>
                @endif
            </td>
            <td>
                @if($bt->transfer_id)
                <a class="m-link-sm" href="{{ route('transfers.show', $bt->transfer_id) }}">Detail</a>
                @endif
            </td>
        </tr>
        @empty
        <tr><td colspan="8" style="text-align:center;color:#aaa;padding:2rem">Žádné převody.</td></tr>
        @endforelse
    </tbody>
</table>
</div>

<div style="margin-top:12px">{{ $transfers->links() }}</div>
</div>
@endsection
