@extends('layouts.app')
@section('title', 'Deník převodů')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs"><a href="{{ route('transfers.index') }}">Převody</a></div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Deník převodů</h2></div>
<div class="m-subtitle">Celkem: {{ $transfers->total() }} záznamů</div>

@if(request('account_id') || request('member_id'))
<div class="m-alert m-alert-info" style="margin-bottom:12px">
    Aktivní filtr:
    @if(request('account_id')) účet #{{ request('account_id') }} @endif
    @if(request('member_id')) člen #{{ request('member_id') }} @endif
    <a class="m-link" href="{{ route('transfers.index') }}" style="margin-left:8px">Zrušit filtr</a>
</div>
@endif

@php
    $nextDir = fn(string $col) => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    $arrow   = fn(string $col) => $sort === $col ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
    $sortUrl = fn(string $col) => request()->fullUrlWithQuery(['sort' => $col, 'dir' => $nextDir($col), 'page' => 1]);
@endphp

<div style="margin-bottom:8px">{{ $transfers->links() }}</div>

<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px"><a class="m-link-sm" href="{{ $sortUrl('id') }}">ID{{ $arrow('id') }}</a></th>
            <th style="width:90px"><a class="m-link-sm" href="{{ $sortUrl('datetime') }}">Datum{{ $arrow('datetime') }}</a></th>
            <th>Popis</th>
            <th>Z účtu</th>
            <th>Na účet</th>
            <th>Člen</th>
            <th style="width:110px;text-align:right"><a class="m-link-sm" href="{{ $sortUrl('amount') }}">Částka{{ $arrow('amount') }}</a></th>
            <th style="width:90px">Typ</th>
            <th style="width:60px">Akce</th>
        </tr>
    </thead>
    <tbody>
        @forelse($transfers as $transfer)
        <tr>
            <td>{{ $transfer->id }}</td>
            <td style="font-size:12px;color:#888">{{ $transfer->datetime?->format('d.m.Y') }}</td>
            <td>{{ $transfer->text }}</td>
            <td>
                @if($transfer->origin) <a class="m-link" href="{{ route('accounts.show', $transfer->origin_id) }}">{{ $transfer->origin->name }}</a>
                @else — @endif
            </td>
            <td>
                @if($transfer->destination) <a class="m-link" href="{{ route('accounts.show', $transfer->destination_id) }}">{{ $transfer->destination->name }}</a>
                @else — @endif
            </td>
            <td>
                @if($transfer->member) <a class="m-link" href="{{ route('members.show', $transfer->member_id) }}">{{ $transfer->member->name }}</a>
                @else — @endif
            </td>
            <td style="text-align:right;font-family:monospace;font-size:12px">
                {{ number_format($transfer->amount, 2, ',', ' ') }} Kč
            </td>
            <td style="font-size:12px">{{ \App\Models\Transfer::typeLabel($transfer->type ?? 0) }}</td>
            <td><a class="m-link-sm" href="{{ route('transfers.show', $transfer->id) }}">Detail</a></td>
        </tr>
        @empty
        <tr><td colspan="9" style="text-align:center;color:#aaa;padding:2rem">Žádné převody nebyly nalezeny.</td></tr>
        @endforelse
    </tbody>
</table>
</div>

<div style="margin-top:4px;display:flex;align-items:center;gap:10px;justify-content:space-between">
    <div style="margin-top:8px">{{ $transfers->links() }}</div>
    <form method="GET" action="{{ route('transfers.index') }}" style="display:flex;align-items:center;gap:6px;font-size:13px;margin-top:8px">
        @if(request('sort'))<input type="hidden" name="sort" value="{{ $sort }}">@endif
        @if(request('dir'))<input type="hidden" name="dir" value="{{ $dir }}">@endif
        @if(request('account_id'))<input type="hidden" name="account_id" value="{{ request('account_id') }}">@endif
        @if(request('member_id'))<input type="hidden" name="member_id" value="{{ request('member_id') }}">@endif
        <span>Na stránku:</span>
        <select class="m-form-select" style="width:70px" name="record_per_page" onchange="this.form.submit()">
            @foreach([50,100,150,200,250,300] as $n)
            <option value="{{ $n }}" @selected($perPage === $n)>{{ $n }}</option>
            @endforeach
        </select>
    </form>
</div>
</div>
@endsection
