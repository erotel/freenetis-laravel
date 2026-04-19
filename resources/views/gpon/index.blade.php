@extends('layouts.app')
@section('title', 'GPON – ONT')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">GPON</div>
@endsection
@section('content')
<div class="m-page">

<div class="m-title-row">
    <h2>GPON – správa ONT</h2>
    <div style="display:flex;gap:8px;align-items:center">
        <a class="m-btn" href="{{ route('gpon.map') }}">🗺 Mapa</a>
        <form method="POST" action="{{ route('gpon.scan') }}" style="margin:0">
            @csrf
            <button class="m-btn m-btn-primary" type="submit">Skenovat nové ONT</button>
        </form>
    </div>
</div>

@if(session('success'))
    <div class="m-alert m-alert-success" style="margin-bottom:16px">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="m-alert m-alert-danger" style="margin-bottom:16px">{{ session('error') }}</div>
@endif

{{-- Počty --}}
<div style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap">
    <a href="{{ route('gpon.index') }}" class="m-card" style="padding:12px 20px;text-decoration:none;min-width:110px;text-align:center">
        <div style="font-size:22px;font-weight:700">{{ $counts['new'] + $counts['registered'] + $counts['removed'] }}</div>
        <div class="m-form-hint">Celkem</div>
    </a>
    <a href="{{ route('gpon.index', ['status' => 'new']) }}" class="m-card" style="padding:12px 20px;text-decoration:none;min-width:110px;text-align:center">
        <div style="font-size:22px;font-weight:700;color:#d97706">{{ $counts['new'] }}</div>
        <div class="m-form-hint">Nové</div>
    </a>
    <a href="{{ route('gpon.index', ['status' => 'registered']) }}" class="m-card" style="padding:12px 20px;text-decoration:none;min-width:110px;text-align:center">
        <div style="font-size:22px;font-weight:700;color:#16a34a">{{ $counts['registered'] }}</div>
        <div class="m-form-hint">Registrované</div>
    </a>
    <a href="{{ route('gpon.index', ['status' => 'removed']) }}" class="m-card" style="padding:12px 20px;text-decoration:none;min-width:110px;text-align:center">
        <div style="font-size:22px;font-weight:700;color:#6b7280">{{ $counts['removed'] }}</div>
        <div class="m-form-hint">Odebrané</div>
    </a>
</div>

{{-- Filtr --}}
<div style="display:flex;gap:8px;margin-bottom:16px;align-items:center;flex-wrap:wrap">
    <span style="font-size:13px;color:var(--fn-text-muted)">Filtr stavu:</span>
    @foreach(['' => 'Vše', 'new' => 'Nové', 'registered' => 'Registrované', 'removed' => 'Odebrané'] as $val => $label)
    <a class="m-btn @if($status === ($val ?: null) || ($val === '' && !$status)) m-btn-primary @endif"
       href="{{ route('gpon.index', $val ? ['status' => $val] : []) }}"
       style="font-size:12px;padding:4px 12px">{{ $label }}</a>
    @endforeach
</div>

<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table">
    <thead>
        <tr>
            <th>Serial</th>
            <th>Port</th>
            <th>ONT ID</th>
            <th>Serv. port</th>
            <th>VLAN</th>
            <th>Stav</th>
            <th>Člen</th>
            <th>Č. domu / jméno</th>
            <th>Přidal</th>
            <th>OLT IP</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        @forelse($onts as $ont)
        <tr>
            <td style="font-family:monospace;font-size:12px">{{ $ont->serial }}</td>
            <td>{{ $ont->gpon_port }}</td>
            <td>{{ $ont->ont_id }}</td>
            <td>{{ $ont->service_port }}</td>
            <td>{{ $ont->vlan }}</td>
            <td>
                @if($ont->reg_status === 'new')
                    <span class="m-tag" style="background:#fef3c7;color:#92400e">Nová</span>
                @elseif($ont->reg_status === 'registered')
                    <span class="m-tag m-tag-green">Registrovaná</span>
                @else
                    <span class="m-tag" style="background:#f3f4f6;color:#6b7280">Odebraná</span>
                @endif
            </td>
            <td>
                @if($ont->member)
                    <a href="{{ route('members.show', $ont->member_id) }}">{{ $ont->member->name ?? $ont->member_id }}</a>
                @else
                    <span class="m-form-hint">—</span>
                @endif
            </td>
            <td>{{ $ont->house_no ?: '—' }}</td>
            <td style="font-size:12px">
                @if($ont->addedBy)
                    <a href="{{ route('users.show', $ont->addedBy->id) }}">{{ $ont->addedBy->login }}</a>
                @else
                    <span class="m-form-hint">{{ $ont->user_name ?? '—' }}</span>
                @endif
            </td>
            <td style="font-size:12px;color:var(--fn-text-muted)">{{ $ont->olt_ip }}</td>
            <td>
                <a class="m-btn" style="font-size:12px;padding:3px 10px"
                   href="{{ route('gpon.show', $ont->id) }}">Detail</a>
            </td>
        </tr>
        @empty
        <tr><td colspan="10" style="text-align:center;color:var(--fn-text-muted);padding:24px">
            Žádné ONT nenalezeny.
        </td></tr>
        @endforelse
    </tbody>
</table>
</div>

@if($onts->hasPages())
<div style="margin-top:16px">{{ $onts->links() }}</div>
@endif

</div>
@endsection
