@extends('layouts.app')
@section('title', 'ONT – ' . $ont->serial)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs"><a href="{{ route('gpon.index') }}">GPON</a> › {{ $ont->serial }}</div>
@endsection
@section('content')
<div class="m-page">

<div class="m-title-row">
    <h2>ONT detail</h2>
    <a class="m-btn" href="{{ route('gpon.index') }}">← Zpět</a>
</div>

@if(session('success'))
    <div class="m-alert m-alert-success" style="margin-bottom:16px">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="m-alert m-alert-danger" style="margin-bottom:16px">{{ session('error') }}</div>
@endif

<div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start">

{{-- Základní info --}}
<div class="m-card" style="flex:1;min-width:280px;max-width:520px">
    <div class="m-card-title">Informace o ONT</div>
    <table style="width:100%;border-collapse:collapse;font-size:14px">
        <tr><td style="padding:5px 0;color:var(--fn-text-muted);width:140px">Serial</td>
            <td style="font-family:monospace">{{ $ont->serial }}</td></tr>
        <tr><td style="padding:5px 0;color:var(--fn-text-muted)">GPON port</td>
            <td>{{ $ont->gpon_port }}</td></tr>
        <tr><td style="padding:5px 0;color:var(--fn-text-muted)">Port num</td>
            <td>{{ $ont->port_num }}</td></tr>
        <tr><td style="padding:5px 0;color:var(--fn-text-muted)">Port index</td>
            <td>{{ $ont->port_index ?? '—' }}</td></tr>
        <tr><td style="padding:5px 0;color:var(--fn-text-muted)">ONT ID</td>
            <td>{{ $ont->ont_id }}</td></tr>
        <tr><td style="padding:5px 0;color:var(--fn-text-muted)">Service port</td>
            <td>{{ $ont->service_port }}</td></tr>
        <tr><td style="padding:5px 0;color:var(--fn-text-muted)">VLAN</td>
            <td>{{ $ont->vlan }}</td></tr>
        <tr><td style="padding:5px 0;color:var(--fn-text-muted)">OLT IP</td>
            <td>{{ $ont->olt_ip ?? '—' }}</td></tr>
        <tr><td style="padding:5px 0;color:var(--fn-text-muted)">Stav</td>
            <td>
                @if($ont->reg_status === 'new')
                    <span class="m-tag" style="background:#fef3c7;color:#92400e">Nová</span>
                @elseif($ont->reg_status === 'registered')
                    <span class="m-tag m-tag-green">Registrovaná</span>
                @else
                    <span class="m-tag" style="background:#f3f4f6;color:#6b7280">Odebraná</span>
                @endif
            </td></tr>
        <tr><td style="padding:5px 0;color:var(--fn-text-muted)">Č. domu</td>
            <td>{{ $ont->house_no ?: '—' }}</td></tr>
        <tr><td style="padding:5px 0;color:var(--fn-text-muted)">Jméno</td>
            <td>{{ $ont->user_name ?: '—' }}</td></tr>
        <tr><td style="padding:5px 0;color:var(--fn-text-muted)">Člen</td>
            <td>
                @if($ont->member)
                    <a href="{{ route('members.show', $ont->member_id) }}">
                        {{ $ont->member->name ?? ('Člen #' . $ont->member_id) }}
                    </a>
                @else
                    <span style="color:var(--fn-text-muted)">—</span>
                @endif
            </td></tr>
        @if($ont->device)
        <tr><td style="padding:5px 0;color:var(--fn-text-muted)">Zařízení</td>
            <td><a href="{{ route('devices.show', $ont->device_id) }}">{{ $ont->device->name ?? ('Zařízení #' . $ont->device_id) }}</a></td></tr>
        @endif
        <tr><td style="padding:5px 0;color:var(--fn-text-muted)">Vytvořeno</td>
            <td>{{ $ont->created_at?->format('d.m.Y H:i') ?? '—' }}</td></tr>
    </table>
</div>

{{-- Akce --}}
<div style="display:flex;flex-direction:column;gap:16px;min-width:240px">

    @if($ont->reg_status === 'new' || $ont->reg_status === 'removed')
    <div class="m-card">
        <div class="m-card-title">Registrovat ONT</div>
        <form method="POST" action="{{ route('gpon.register', $ont->id) }}">
            @csrf
            <div class="m-form-group">
                <label class="m-form-label">Číslo domu</label>
                <input class="m-form-input" type="text" name="house_no" value="{{ $ont->house_no }}" maxlength="32" placeholder="např. 123">
            </div>
            <div class="m-form-group">
                <label class="m-form-label">Jméno uživatele</label>
                <input class="m-form-input" type="text" name="user_name" value="{{ $ont->user_name }}" maxlength="128" placeholder="Příjmení Jméno">
            </div>
            <div class="m-actions" style="margin-top:12px">
                <button class="m-btn m-btn-success" type="submit">Registrovat</button>
            </div>
        </form>
    </div>
    @endif

    @if($ont->reg_status === 'registered')
    <div class="m-card">
        <div class="m-card-title">Odebrat ONT</div>
        <p class="m-form-hint">ONT bude odebrána z OLT a označena jako odebraná.</p>
        <form method="POST" action="{{ route('gpon.remove', $ont->id) }}"
              onsubmit="return confirm('Opravdu odebrat ONT {{ $ont->serial }}?')">
            @csrf
            <button class="m-btn" style="background:#fee2e2;color:#b91c1c;border-color:#fca5a5" type="submit">Odebrat ONT</button>
        </form>
    </div>
    @endif

</div>
</div>

</div>
@endsection
