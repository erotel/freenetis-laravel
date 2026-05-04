@extends('layouts.app')
@section('title', 'Statistiky')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">Statistiky</div>
@endsection

@section('styles')
<style>
.stats-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:20px;margin-bottom:20px}
@media(max-width:900px){.stats-grid{grid-template-columns:1fr}}
.stat-card{padding:16px 20px}
.stat-card h3{margin:0 0 16px;font-size:15px;font-weight:600;color:var(--fn-text)}
.chart-wrap{position:relative;height:260px}
</style>
@endsection

@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Statistiky</h2></div>
<div class="m-subtitle">Přehled růstu, plateb a poplatků</div>

<div style="display:flex;gap:4px;margin-bottom:20px;flex-wrap:wrap">
    <a class="m-btn m-btn-primary" href="{{ route('stats.index') }}">Růst a platby</a>
    <a class="m-btn"               href="{{ route('stats.cashflow') }}">Cash flow</a>
</div>

<div class="stats-grid">

    {{-- 1. Přírůstek / úbytek --}}
    <div class="m-card stat-card">
        <h3>Přírůstek a úbytek členů (posledních 24 měsíců)</h3>
        <div class="chart-wrap"><canvas id="chartIncDec"></canvas></div>
    </div>

    {{-- 2. Kumulativní růst --}}
    <div class="m-card stat-card">
        <h3>Celkový počet aktivních členů (posledních 24 měsíců)</h3>
        <div class="chart-wrap"><canvas id="chartGrowth"></canvas></div>
    </div>

    {{-- 3. Přijaté platby --}}
    <div class="m-card stat-card">
        <h3>Přijaté platby {{ $year }} (Kč)</h3>
        <div class="chart-wrap"><canvas id="chartPayments"></canvas></div>
    </div>

    {{-- 4. Poplatky --}}
    <div class="m-card stat-card">
        <h3>Poplatky členů {{ $year }} (Kč)</h3>
        <div class="chart-wrap"><canvas id="chartFees"></canvas></div>
    </div>

</div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>

<script>
(function(){
    var dark = document.documentElement.getAttribute('data-theme') === 'dark';
    var gridColor  = dark ? 'rgba(255,255,255,.08)' : 'rgba(0,0,0,.06)';
    var textColor  = dark ? '#aaa' : '#666';
    var orange     = '#e8651a';
    var orangeAlpha= 'rgba(232,101,26,.15)';
    var red        = '#c0392b';
    var redAlpha   = 'rgba(192,57,43,.15)';
    var blue       = '#2980b9';
    var blueAlpha  = 'rgba(41,128,185,.15)';
    var green      = '#27ae60';
    var greenAlpha = 'rgba(39,174,96,.15)';

    var months24  = @json($months24);
    var incrData  = @json($incrData);
    var decrData  = @json($decrData);
    var growth    = @json($growthData);
    var payMonths = @json($payMonths);
    var payData   = @json($payData);
    var feeData   = @json($feeData);

    var defaults = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { labels: { color: textColor, font: { size: 12 } } }
        },
        scales: {
            x: { ticks: { color: textColor, font: { size: 11 } }, grid: { color: gridColor } },
            y: { ticks: { color: textColor, font: { size: 11 } }, grid: { color: gridColor }, beginAtZero: true }
        }
    };

    function merge(a, b){ return Object.assign({}, a, b, {
        plugins: Object.assign({}, a.plugins, b.plugins||{}),
        scales: Object.assign({}, a.scales, b.scales||{})
    }); }

    // 1. Přírůstek / úbytek
    new Chart(document.getElementById('chartIncDec'), {
        type: 'bar',
        data: {
            labels: months24,
            datasets: [
                { label: 'Přírůstek', data: incrData, backgroundColor: orange, borderColor: orange, borderRadius: 3 },
                { label: 'Úbytek',    data: decrData, backgroundColor: red,    borderColor: red,    borderRadius: 3 }
            ]
        },
        options: defaults
    });

    // 2. Kumulativní počet
    new Chart(document.getElementById('chartGrowth'), {
        type: 'line',
        data: {
            labels: months24,
            datasets: [{
                label: 'Aktivní členové',
                data: growth,
                borderColor: orange,
                backgroundColor: orangeAlpha,
                pointBackgroundColor: orange,
                pointRadius: 3,
                fill: true,
                tension: 0.3
            }]
        },
        options: defaults
    });

    // 3. Platby
    new Chart(document.getElementById('chartPayments'), {
        type: 'bar',
        data: {
            labels: payMonths,
            datasets: [{
                label: 'Platby (Kč)',
                data: payData,
                backgroundColor: blue,
                borderColor: blue,
                borderRadius: 3
            }]
        },
        options: defaults
    });

    // 4. Poplatky
    new Chart(document.getElementById('chartFees'), {
        type: 'bar',
        data: {
            labels: payMonths,
            datasets: [{
                label: 'Poplatky (Kč)',
                data: feeData,
                backgroundColor: green,
                borderColor: green,
                borderRadius: 3
            }]
        },
        options: defaults
    });
})();
</script>
@endsection
