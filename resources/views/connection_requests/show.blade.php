@extends('layouts.app')
@section('title', 'Žádost o připojení #' . $cr->id)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('connection_requests.index') }}">Žádosti o připojení</a> &raquo; #{{ $cr->id }}
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Žádost o připojení #{{ $cr->id }}</h2></div>

@if($canEdit && $cr->state === \App\Models\ConnectionRequest::STATE_UNDECIDED)
<div class="m-actions">
    <a class="m-btn m-btn-success" href="{{ route('devices.create_from_cr', $cr->id) }}">✓ Schválit a vytvořit zařízení</a>
    <form method="POST" action="{{ route('connection_requests.reject', $cr->id) }}" style="display:inline"
          onsubmit="return confirm('Zamítnout žádost #{{ $cr->id }}?')">
        @csrf @method('DELETE')
        <button class="m-btn m-btn-danger" type="submit">✕ Zamítnout</button>
    </form>
</div>
@endif

<div class="m-actions" style="margin-top:0">
    <a class="m-btn" href="{{ route('connection_requests.index') }}">&laquo; Zpět na seznam</a>
</div>

@if($pppoeEnabled && $cr->pppoe_username)
<div class="m-card" style="max-width:480px;border:2px solid #2563eb">
    <div class="m-card-title">PPPoE přístup — zadejte do zařízení</div>
    <div class="m-field"><span class="m-field-label">Uživatel</span><span class="m-field-value" style="font-family:monospace">{{ $cr->pppoe_username }}</span></div>
    <div class="m-field">
        <span class="m-field-label">Heslo</span>
        <span class="m-field-value" style="font-family:monospace">
            <span id="crppp" data-k="{{ $cr->pppoe_secret }}">••••••••</span>
            <button type="button" style="background:none;border:0;color:#2563eb;cursor:pointer;padding:0 0 0 8px"
                onclick="var e=document.getElementById('crppp');var k=e.getAttribute('data-k');if(e.textContent===k){e.textContent='••••••••';this.textContent='zobrazit';}else{e.textContent=k;this.textContent='skrýt';}">zobrazit</button>
        </span>
    </div>
    <div class="m-form-hint" style="padding:8px 12px 4px">
        Zadejte tyto údaje do PPPoE klienta zařízení. Provoz do internetu se pustí až po <strong>schválení</strong> žádosti.
    </div>
</div>
@endif

@php
    $stateClass = match($cr->state) {
        \App\Models\ConnectionRequest::STATE_UNDECIDED => 'm-tag-amber',
        \App\Models\ConnectionRequest::STATE_APPROVED  => 'm-tag-green',
        default => 'm-tag-red',
    };
@endphp

<div class="m-card" style="max-width:480px">
    <div class="m-card-title">Detail žádosti</div>
    <div class="m-field"><span class="m-field-label">ID</span><span class="m-field-value">{{ $cr->id }}</span></div>
    <div class="m-field">
        <span class="m-field-label">Člen</span>
        <span class="m-field-value">
            @if($cr->member) <a href="{{ route('members.show', $cr->member_id) }}">{{ $cr->member->name }}</a>
            @else — @endif
        </span>
    </div>
    <div class="m-field"><span class="m-field-label">IP adresa</span><span class="m-field-value" style="font-family:monospace">{{ $cr->ip_address ?: '— doplní technik' }}</span></div>
    <div class="m-field"><span class="m-field-label">MAC adresa</span><span class="m-field-value" style="font-family:monospace">{{ $cr->mac_address ?: '— doplní technik' }}</span></div>
    <div class="m-field">
        <span class="m-field-label">Subnet</span>
        <span class="m-field-value">
            @if($cr->subnet) <a href="{{ route('subnets.show', $cr->subnet_id) }}">{{ $cr->subnet->name }}</a>
            @else — @endif
        </span>
    </div>
    <div class="m-field"><span class="m-field-label">Typ zařízení</span><span class="m-field-value">{{ $cr->deviceType?->value ?? '—' }}</span></div>
    <div class="m-field"><span class="m-field-label">Šablona zařízení</span><span class="m-field-value">{{ $cr->deviceTemplate?->name ?? '—' }}</span></div>
    <div class="m-field">
        <span class="m-field-label">Stav</span>
        <span class="m-field-value"><span class="m-tag {{ $stateClass }}">{{ $cr->stateName() }}</span></span>
    </div>
    <div class="m-field"><span class="m-field-label">Přidal</span><span class="m-field-value">{{ $cr->addedUser?->login ?? '—' }}</span></div>
    <div class="m-field"><span class="m-field-label">Vytvořeno</span><span class="m-field-value">{{ \Carbon\Carbon::parse($cr->created_at)->format('d.m.Y H:i') }}</span></div>
    @if($cr->decided_at)
    <div class="m-field"><span class="m-field-label">Rozhodl</span><span class="m-field-value">{{ $cr->decidedUser?->login ?? '—' }}</span></div>
    <div class="m-field"><span class="m-field-label">Rozhodnuto</span><span class="m-field-value">{{ \Carbon\Carbon::parse($cr->decided_at)->format('d.m.Y H:i') }}</span></div>
    @endif
    @if($cr->comment)
    <div class="m-field"><span class="m-field-label">Komentář</span><span class="m-field-value">{{ $cr->comment }}</span></div>
    @endif
    @if($cr->device_id)
    <div class="m-field">
        <span class="m-field-label">Zařízení</span>
        <span class="m-field-value"><a href="{{ route('devices.show', $cr->device_id) }}">{{ $cr->device?->name ?? $cr->device_id }}</a></span>
    </div>
    @endif
</div>
</div>
@endsection
