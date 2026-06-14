@extends('field.layout')

@section('title', $device->name)

@section('content')
@if($member)
    <a href="{{ route('field.member', $member->id) }}" class="f-back">‹ {{ $member->name }}</a>
@else
    <a href="{{ route('field.search') }}" class="f-back">‹ Hledat</a>
@endif

{{-- HLAVIČKA --}}
<div class="f-card">
    <div style="font-size:21px;font-weight:700;line-height:1.2">{{ $device->name }}</div>
    <div style="font-size:14px;color:var(--muted);margin-top:2px">
        {{ $device->enumType?->value ?? 'Zařízení' }}
        @if($device->trade_name) · {{ $device->trade_name }} @endif
    </div>
    @if($ips->isNotEmpty() || $ip6s->isNotEmpty())
        <div style="margin-top:10px">
            @foreach($ips as $ip)
                <a class="f-link" href="http://{{ $ip }}" target="_blank" rel="noopener">
                    <span class="f-ic">🌐</span><span style="font-family:monospace">{{ $ip }}</span>
                </a>
            @endforeach
            @foreach($ip6s as $ip6)
                <div class="f-link">
                    <span class="f-ic">🌐</span><span style="font-family:monospace;font-size:14px">{{ $ip6 }}</span>
                </div>
            @endforeach
        </div>
    @endif
</div>

{{-- INFO --}}
<div class="f-card">
    <h2 class="f-section-title">Informace</h2>
    @if($member)
        <a class="f-kv" href="{{ route('field.member', $member->id) }}" style="color:var(--text)">
            <span class="k">Vlastník</span><span class="v" style="color:var(--accent)">{{ $member->name }} ›</span>
        </a>
    @endif
    @if($device->operating_system)
        <div class="f-kv"><span class="k">OS</span><span class="v">{{ $device->operating_system }}</span></div>
    @endif
    @php $buyDate = $device->buy_date ? \Carbon\Carbon::parse($device->buy_date) : null; @endphp
    @if($buyDate && $buyDate->year > 1970)
        <div class="f-kv"><span class="k">Pořízeno</span><span class="v">{{ $buyDate->format('d.m.Y') }}</span></div>
    @endif
</div>

{{-- INTERFACES --}}
<div class="f-card">
    <h2 class="f-section-title">Rozhraní</h2>
    @forelse($device->ifaces as $iface)
        @php
            $ifaceIps  = $iface->ipAddresses->pluck('ip_address')->filter();
            $ifaceIp6s = $iface->ip6Addresses->pluck('ip_address')->filter();
        @endphp
        <div class="f-iface">
            <div class="f-iface-name">{{ $iface->name ?: ($iface->type_label ?? 'rozhraní') }}</div>
            @foreach($ifaceIps as $ip)
                <div class="f-iface-line"><span class="tag">IP</span>{{ $ip }}</div>
            @endforeach
            @foreach($ifaceIp6s as $ip6)
                <div class="f-iface-line"><span class="tag">IPv6</span>{{ $ip6 }}</div>
            @endforeach
            @if($iface->mac)
                <div class="f-iface-line muted"><span class="tag">MAC</span>{{ $iface->mac }}</div>
            @endif
            @if($ifaceIps->isEmpty() && $ifaceIp6s->isEmpty() && !$iface->mac)
                <div class="f-iface-line muted">—</div>
            @endif
        </div>
    @empty
        <div class="f-empty">Žádná rozhraní.</div>
    @endforelse
</div>
@endsection
