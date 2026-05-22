@extends('layouts.app')
@section('title', 'Účty')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs"><a href="{{ route('accounts.index') }}">Účty</a></div>
@endsection
@section('content')
<div class="m-page">
<div class="m-actions" style="margin-bottom:8px">
    <a class="m-btn" href="{{ url()->previous() }}">← Zpět</a>
</div>
<div class="m-title-row"><h2>Seznam účtů</h2></div>
<div class="m-subtitle">Celkem: {{ $accounts->total() }} záznamů</div>

@php
    $tabs = ['all' => 'Všechny', 'credit' => 'Členské', 'project' => 'Projektové', 'other' => 'Ostatní'];
@endphp
<div style="display:flex;gap:4px;margin-bottom:16px;flex-wrap:wrap">
    @foreach($tabs as $key => $label)
    @if($type === $key)
        <span class="m-btn m-btn-primary" style="cursor:default">{{ $label }}</span>
    @else
        <a class="m-btn" href="{{ request()->fullUrlWithQuery(['type' => $key, 'page' => 1]) }}">{{ $label }}</a>
    @endif
    @endforeach
</div>

@if($canNew)
<div class="m-actions">
    <a class="m-btn m-btn-success" href="{{ route('accounts.create') }}">+ Přidat projektový účet</a>
</div>
@endif

@php
    $nextDir = fn(string $col) => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    $arrow   = fn(string $col) => $sort === $col ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
    $sortUrl = fn(string $col) => request()->fullUrlWithQuery(['sort' => $col, 'dir' => $nextDir($col), 'page' => 1]);
@endphp

<div style="margin-bottom:8px">{{ $accounts->links() }}</div>

<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px"><a class="m-link-sm" href="{{ $sortUrl('id') }}">ID{{ $arrow('id') }}</a></th>
            <th><a class="m-link-sm" href="{{ $sortUrl('name') }}">Název{{ $arrow('name') }}</a></th>
            <th style="width:130px">Typ účtu</th>
            <th>Člen</th>
            <th style="width:130px;text-align:right"><a class="m-link-sm" href="{{ $sortUrl('balance') }}">Zůstatek{{ $arrow('balance') }}</a></th>
            <th style="width:80px">Akce</th>
        </tr>
    </thead>
    <tbody>
        @forelse($accounts as $account)
        <tr>
            <td>{{ $account->id }}</td>
            <td><a class="m-link" href="{{ route('accounts.show', $account->id) }}">{{ $account->name }}</a></td>
            <td>{{ $account->accountAttribute?->name ?? '—' }}</td>
            <td>
                @if($account->member)
                    <a class="m-link" href="{{ route('members.show', $account->member_id) }}">{{ $account->member->name }}</a>
                @else —
                @endif
            </td>
            <td style="text-align:right;font-family:monospace;font-size:14px">
                <span style="color:{{ $account->balance > 0 ? '#27ae60' : ($account->balance < 0 ? '#c0392b' : 'inherit') }}">
                    {{ number_format($account->balance, 2, ',', ' ') }} Kč
                </span>
            </td>
            <td>
                <div style="display:flex;gap:6px">
                    <a class="m-link-sm" href="{{ route('accounts.show', $account->id) }}">Detail</a>
                    @if($canEdit)
                    <a class="m-link-sm" href="{{ route('accounts.edit', $account->id) }}">Upravit</a>
                    @endif
                </div>
            </td>
        </tr>
        @empty
        <tr><td colspan="6" style="text-align:center;color:#aaa;padding:2rem">Žádné účty nebyly nalezeny.</td></tr>
        @endforelse
    </tbody>
</table>
</div>

<div style="margin-top:4px;display:flex;align-items:center;gap:10px;justify-content:space-between">
    <div style="margin-top:8px">{{ $accounts->links() }}</div>
    <form method="GET" action="{{ route('accounts.index') }}" style="display:flex;align-items:center;gap:6px;font-size:16px;margin-top:8px">
        @if(request('sort'))<input type="hidden" name="sort" value="{{ $sort }}">@endif
        @if(request('dir'))<input type="hidden" name="dir" value="{{ $dir }}">@endif
        @if($type !== 'all')<input type="hidden" name="type" value="{{ $type }}">@endif
        <span>Na stránku:</span>
        <select class="m-form-select" style="width:70px" name="record_per_page" onchange="this.form.submit()">
            @foreach([50,100,150,200,250,300,350,400,450,500] as $n)
            <option value="{{ $n }}" @selected($perPage === $n)>{{ $n }}</option>
            @endforeach
        </select>
    </form>
</div>
</div>
@endsection
