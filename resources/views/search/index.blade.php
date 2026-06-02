@extends('layouts.app')
@section('title', 'Vyhledávání')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">Vyhledávání</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Vyhledávání</h2></div>

<div class="m-card" style="margin-bottom:16px;padding:14px 1.25rem">
    <form method="GET" action="{{ route('search') }}" style="display:flex;gap:8px;align-items:center">
        <input class="m-form-input" type="text" name="q" value="{{ $query }}"
               placeholder="Hledat člena, IP, MAC, VS..." style="max-width:380px">
        <button class="m-btn m-btn-primary" type="submit">Hledat</button>
    </form>
</div>

@if(strlen($query) > 0 && strlen($query) < 2)
<div class="m-alert m-alert-warning">Zadejte alespoň 2 znaky.</div>
@elseif(strlen($query) >= 2 && empty($results))
<div class="m-alert m-alert-info">Žádné výsledky pro <strong>{{ $query }}</strong>.</div>
@endif

@php
    $sectionHeader = function (string $label, int $shown, int $total, ?string $listingUrl) {
        $countLabel = $total > $shown ? "{$shown} z {$total}" : (string) $total;
        $more = ($total > $shown && $listingUrl)
            ? ' &nbsp;·&nbsp; <a class="m-link" href="' . e($listingUrl) . '">Otevřít všechny v seznamu →</a>'
            : '';
        return "<div class=\"m-section\">{$label} ({$countLabel}){$more}</div>";
    };
@endphp

@if(!empty($results['members']))
{!! $sectionHeader('Členové', count($results['members']), $totals['members'] ?? count($results['members']), route('members.index', ['search' => $query])) !!}
<div class="m-card" style="padding:0;overflow-x:auto;margin-bottom:16px">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr><th style="width:50px">ID</th><th>Jméno</th><th style="width:160px">Typ</th><th>Adresa</th><th style="width:130px">Variabilní symbol</th></tr>
    </thead>
    <tbody>
        @foreach($results['members'] as $m)
        @php
            $streetPart = trim(($m->street ?? '') . ' ' . ($m->street_number ?? ''));
            $townPart   = trim(($m->zip_code ?? '') . ' ' . ($m->town ?? ''));
            $address    = trim(implode(', ', array_filter([$streetPart, $townPart])));
        @endphp
        <tr>
            <td>{{ $m->id }}</td>
            <td><a class="m-link" href="{{ route('members.show', $m->id) }}">{{ $m->name }}</a></td>
            <td>{{ \App\Helpers\MemberType::label($m->type) }}</td>
            <td>{{ $address !== '' ? $address : '—' }}</td>
            <td style="font-family:monospace;font-size:14px">{{ $m->variable_symbol ?? '—' }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
</div>
@endif

@if(!empty($results['users']))
{!! $sectionHeader('Uživatelé', count($results['users']), $totals['users'] ?? count($results['users']), route('users.index', ['search' => $query])) !!}
<div class="m-card" style="padding:0;overflow-x:auto;margin-bottom:16px">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr><th>Login</th><th>Jméno</th><th>Člen</th></tr>
    </thead>
    <tbody>
        @foreach($results['users'] as $u)
        <tr>
            <td>{{ $u->login }}</td>
            <td>{{ $u->name }} {{ $u->surname }}</td>
            <td><a class="m-link" href="{{ route('members.show', $u->member_id) }}">{{ $u->member_name }}</a></td>
        </tr>
        @endforeach
    </tbody>
</table>
</div>
@endif

@if(!empty($results['devices']))
{!! $sectionHeader('Zařízení / IP adresy', count($results['devices']), $totals['devices'] ?? count($results['devices']), route('devices.index', ['search' => $query])) !!}
<div class="m-card" style="padding:0;overflow-x:auto;margin-bottom:16px">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr><th>Zařízení</th><th style="width:150px">MAC</th><th style="width:140px">IP adresa</th><th style="width:200px">IPv6</th><th>Adresa</th><th>Člen</th></tr>
    </thead>
    <tbody>
        @foreach($results['devices'] as $d)
        @php
            $streetPart = trim(($d->street ?? '') . ' ' . ($d->street_number ?? ''));
            $address    = trim(implode(', ', array_filter([$streetPart, $d->town ?? null])));
        @endphp
        <tr>
            <td><a class="m-link" href="{{ route('devices.show', $d->id) }}">{{ $d->device_name }}</a></td>
            <td style="font-family:monospace;font-size:14px">{{ $d->mac ?? '—' }}</td>
            <td style="font-family:monospace;font-size:14px">{{ $d->ip_address ?? '—' }}</td>
            <td style="font-family:monospace;font-size:14px">{{ $d->ipv6_address ?? '—' }}</td>
            <td>{{ $address !== '' ? $address : '—' }}</td>
            <td><a class="m-link" href="{{ route('members.show', $d->member_id) }}">{{ $d->member_name }}</a></td>
        </tr>
        @endforeach
    </tbody>
</table>
</div>
@endif

@if(!empty($results['subnets']))
{!! $sectionHeader('Subnety', count($results['subnets']), $totals['subnets'] ?? count($results['subnets']), route('subnets.index', ['search' => $query])) !!}
<div class="m-card" style="padding:0;overflow-x:auto;margin-bottom:16px">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr><th>Název</th><th>Adresa sítě</th><th style="width:160px">Maska</th></tr>
    </thead>
    <tbody>
        @foreach($results['subnets'] as $s)
        <tr>
            <td><a class="m-link" href="{{ route('subnets.show', $s->id) }}">{{ $s->name }}</a></td>
            <td style="font-family:monospace;font-size:14px">{{ $s->network_address }}</td>
            <td style="font-family:monospace;font-size:14px">{{ $s->netmask }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
</div>
@endif

</div>
@endsection
