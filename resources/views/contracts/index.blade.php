@extends('layouts.app')

@section('title', 'Smlouvy')

@section('menu')
<x-freenetis-menu />
@endsection

@section('breadcrumbs')
<div id="breadcrumbs">
    Smlouvy
</div>
@endsection

@section('content')
<div class="member-page">

<div class="m-actions" style="margin-bottom:8px">
    <a class="m-btn" href="{{ url()->previous() }}">← Zpět</a>
</div>

<div class="member-title-row">
    <h2>Smlouvy</h2>
</div>

<form method="GET" action="{{ route('contracts.index') }}" style="margin:8px 0 16px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <input type="text" name="q" value="{{ $q ?? '' }}"
           placeholder="Hledat: č. smlouvy, jméno, VS, ID člena"
           style="flex:1;min-width:260px;padding:6px 10px;border:1px solid #ccc;border-radius:4px">
    <select name="status" style="padding:6px 10px;border:1px solid #ccc;border-radius:4px">
        <option value="">— Všechny stavy —</option>
        <option value="signed"       {{ ($status ?? '') === 'signed'       ? 'selected' : '' }}>Podepsané</option>
        <option value="draft"        {{ ($status ?? '') === 'draft'        ? 'selected' : '' }}>Rozpracované</option>
        <option value="otp_sent"     {{ ($status ?? '') === 'otp_sent'     ? 'selected' : '' }}>OTP odesláno</option>
        <option value="otp_verified" {{ ($status ?? '') === 'otp_verified' ? 'selected' : '' }}>OTP ověřeno</option>
        <option value="canceled"     {{ ($status ?? '') === 'canceled'     ? 'selected' : '' }}>Zrušené</option>
    </select>
    <button class="m-btn" type="submit">Hledat</button>
    @if(($q ?? '') !== '' || ($status ?? '') !== '')
    <a class="m-link" href="{{ route('contracts.index') }}">Zrušit filtr</a>
    @endif
</form>

<div class="m-metrics">
    @php
        $counts = [
            'signed'       => (int) ($totalCounts['signed']       ?? 0),
            'draft'        => (int) ($totalCounts['draft']        ?? 0)
                            + (int) ($totalCounts['otp_sent']     ?? 0)
                            + (int) ($totalCounts['otp_verified'] ?? 0),
            'canceled'     => (int) ($totalCounts['canceled']     ?? 0),
        ];
    @endphp
    <div class="m-metric"><div class="m-metric-label">Podepsané</div><div class="m-metric-value green">{{ $counts['signed'] }}</div></div>
    <div class="m-metric"><div class="m-metric-label">Čekající</div><div class="m-metric-value" style="color:#d97706">{{ $counts['draft'] }}</div></div>
    <div class="m-metric"><div class="m-metric-label">Zrušené</div><div class="m-metric-value red">{{ $counts['canceled'] }}</div></div>
</div>

@php
$statusColors = [
    'draft'        => '#d97706',
    'otp_sent'     => '#d97706',
    'otp_verified' => '#2563eb',
    'signed'       => '#16a34a',
    'canceled'     => '#dc2626',
];
@endphp

<table class="m-table" style="margin-top:16px">
    <thead>
        <tr>
            <th>Č. smlouvy</th>
            <th>Člen / Zákazník</th>
            <th>VS</th>
            <th>Stav</th>
            <th>Vytvořeno</th>
            <th>Podepsáno</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
    @foreach($contracts as $c)
    @php $party = $c->parties->first(); @endphp
    <tr>
        <td style="font-family:monospace;font-size:14px">{{ $c->contract_no }}</td>
        <td>
            @if($c->member_id)
            <a class="m-link" href="{{ route('members.show', $c->member_id) }}">
                {{ $party?->full_name ?? ('Člen #' . $c->member_id) }}
            </a>
            @else
            {{ $party?->full_name ?? '—' }}
            @endif
        </td>
        <td style="font-family:monospace;font-size:14px">{{ $party?->variable_symbol ?? '—' }}</td>
        <td>
            <span style="display:inline-block;padding:1px 8px;border-radius:10px;font-size:13px;font-weight:600;background:{{ $statusColors[$c->status] ?? '#888' }}1a;color:{{ $statusColors[$c->status] ?? '#888' }};border:1px solid {{ $statusColors[$c->status] ?? '#888' }}44">
                {{ $c->statusLabel() }}
            </span>
        </td>
        <td style="font-size:14px;color:#888">{{ \Carbon\Carbon::parse($c->created_at)->format('d.m.Y') }}</td>
        <td style="font-size:14px;color:#888">{{ $c->signed_at ? $c->signed_at->format('d.m.Y') : '—' }}</td>
        <td>
            @if($c->member_id)
            <a class="m-link-sm" href="{{ route('contracts.show', $c->member_id) }}">Detail</a>
            @endif
            @if($c->status === 'signed' && $c->pdf_path)
            <a class="m-link-sm" href="{{ route('contracts.download', $c->id) }}" style="margin-left:6px">PDF</a>
            @endif
        </td>
    </tr>
    @endforeach
    </tbody>
</table>

<div style="margin-top:16px">
    {{ $contracts->links('vendor.pagination.kohana') }}
</div>

</div>
@endsection
