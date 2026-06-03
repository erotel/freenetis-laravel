@extends('layouts.app')
@section('title', 'Kandidáti na ukončení')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs"><a href="{{ route('members.index') }}">Zákazníci</a> &raquo; Kandidáti na ukončení</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Kandidáti na ukončení smlouvy</h2></div>
<div class="m-subtitle">
    Celkem: {{ $rows->count() }} &nbsp;|&nbsp;
    <span style="color:#888">Nezaplacený poplatek z předchozího měsíce nebo staršího (per VOP).</span>
</div>

@if(session('success'))
<div class="m-alert m-alert-success">{{ session('success') }}</div>
@endif

@if($rows->isEmpty())
<div class="m-card">
    <div style="text-align:center;color:#aaa;padding:2rem">Žádní kandidáti.</div>
</div>
@else
<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px">ID</th>
            <th>Jméno</th>
            <th style="width:120px">Typ</th>
            <th style="width:140px">VS</th>
            <th style="width:120px;text-align:right">Stav účtu</th>
            <th style="width:120px">Blokováno od</th>
            <th style="width:70px;text-align:right">Dní</th>
            <th style="width:220px">Akce</th>
        </tr>
    </thead>
    <tbody>
        @foreach($rows as $m)
        @php
            $days = $m->payment_blocked_since
                ? (int) ((strtotime($today) - strtotime($m->payment_blocked_since)) / 86400)
                : 0;
            $endLink = route('members.end-membership', $m->id)
                . '?leaving_date=' . urlencode($today);
        @endphp
        @php
            $typeBadge = match((int)$m->type) {
                2  => ['m-badge-blue',  'Zákazník'],
                90 => ['m-badge-green', 'Člen'],
                3  => ['m-badge-green', 'Čestný'],
                default => ['m-badge-gray', 'Typ '.$m->type],
            };
        @endphp
        <tr>
            <td>{{ $m->id }}</td>
            <td><a class="m-link" href="{{ route('members.show', $m->id) }}">{{ $m->name }}</a></td>
            <td><span class="m-badge {{ $typeBadge[0] }}" style="font-size:13px">{{ $typeBadge[1] }}</span></td>
            <td style="font-family:monospace;font-size:14px">{{ $m->variable_symbols ?? '—' }}</td>
            <td style="text-align:right;font-family:monospace;color:{{ $m->balance < 0 ? '#c00' : '#333' }}">
                {{ number_format((float) $m->balance, 2, ',', ' ') }} Kč
            </td>
            <td>{{ $m->payment_blocked_since }}</td>
            <td style="text-align:right">{{ $days }}</td>
            <td>
                <div style="display:flex;gap:6px;align-items:center">
                    @if($canEdit)
                    <a class="m-btn m-btn-danger m-btn-sm" href="{{ $endLink }}">Ukončit</a>
                    <form method="POST" action="{{ route('members.payment-block.reset', $m->id) }}"
                          style="display:inline"
                          onsubmit="return confirm('Reset blokace u {{ addslashes($m->name) }}?')">
                        @csrf
                        <button class="m-btn m-btn-sm" type="submit">Reset blokace</button>
                    </form>
                    @endif
                </div>
            </td>
        </tr>
        @endforeach
    </tbody>
</table>
</div>
@endif
</div>
@endsection
