@extends('layouts.app')
@section('title', 'Statistiky — Cash flow')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('stats.index') }}">Statistiky</a> &raquo; Cash flow
</div>
@endsection

@section('styles')
<style>
.cf-table{width:100%;border-collapse:collapse}
.cf-table th, .cf-table td{padding:8px 12px;border-bottom:1px solid var(--fn-border, #e5e5e5)}
.cf-table th{text-align:right;font-weight:600;font-size:13px;background:var(--fn-bg-soft, #f7f7f7)}
.cf-table th.col-month{text-align:left}
.cf-table td{text-align:right;font-variant-numeric:tabular-nums}
.cf-table td.col-month{text-align:left;font-weight:500}
.cf-net-pos{color:#27ae60}
.cf-net-neg{color:#c0392b}
.cf-empty{color:#999}
.cf-toolbar{display:flex;gap:12px;align-items:center;margin-bottom:16px;flex-wrap:wrap}
</style>
@endsection

@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Statistiky</h2></div>
<div class="m-subtitle">Cash flow — měsíční stavy účtů a strženo členům</div>

<div style="display:flex;gap:4px;margin-bottom:20px;flex-wrap:wrap">
    <a class="m-btn"               href="{{ route('stats.index') }}">Růst a platby</a>
    <a class="m-btn m-btn-primary" href="{{ route('stats.cashflow') }}">Cash flow</a>
</div>

<div class="cf-toolbar">
    <form method="GET" action="{{ route('stats.cashflow') }}" style="display:flex;gap:8px;align-items:center">
        <label class="m-form-label" style="margin:0">Rok:</label>
        <select class="m-form-select" name="year" onchange="this.form.submit()">
            <option value="">posledních 12 měsíců</option>
            @foreach($availableYears as $y)
            <option value="{{ $y }}" {{ $year === $y ? 'selected' : '' }}>{{ $y }}</option>
            @endforeach
        </select>
        <noscript><button class="m-btn" type="submit">Zobrazit</button></noscript>
    </form>
</div>

<div class="m-card" style="padding:0;overflow-x:auto">
<table class="cf-table">
    <thead>
        <tr>
            <th class="col-month">Měsíc</th>
            <th>Strženo zákazníkům<br><span style="font-weight:400;font-size:11px;color:#888">(typ 2)</span></th>
            <th>Strženo členům<br><span style="font-weight:400;font-size:11px;color:#888">(typ 90)</span></th>
        </tr>
    </thead>
    <tbody>
        @forelse($rows as $r)
        <tr>
            <td class="col-month">{{ $r['month'] }}</td>
            <td class="{{ $r['deducted'][2] > 0 ? '' : 'cf-empty' }}">
                {{ number_format($r['deducted'][2], 0, ',', ' ') }} Kč
            </td>
            <td class="{{ $r['deducted'][90] > 0 ? '' : 'cf-empty' }}">
                {{ number_format($r['deducted'][90], 0, ',', ' ') }} Kč
            </td>
        </tr>
        @empty
        <tr><td colspan="3" class="cf-empty" style="text-align:center;padding:20px">Žádná data</td></tr>
        @endforelse
    </tbody>
</table>
</div>
<div class="m-form-hint" style="margin-top:8px">
    <strong>Strženo</strong> = pravidelné členské + vstupní + zařízení (transfer types 1, 2, 5)
    z kreditních účtů členů daného typu.
</div>
</div>
@endsection
