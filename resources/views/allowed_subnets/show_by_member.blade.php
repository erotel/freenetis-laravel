@extends('layouts.app')
@section('title', 'Povolené podsítě člena ' . $member->name)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('members.index') }}">Členové</a> &raquo;
    <a href="{{ route('members.show', $member->id) }}">{{ $member->name }}</a> &raquo;
    Povolené podsítě
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Povolené podsítě člena {{ $member->name }}</h2></div>

<div class="m-actions">
    <a class="m-btn" href="{{ route('members.show', $member->id) }}">&larr; Profil člena</a>
</div>

@php
    $enabledCount = $allowedSubnets->where('enabled', true)->count();
@endphp

<div class="m-card m-alert-info" style="max-width:720px;margin-bottom:16px;background:#eef6ff;border-left:4px solid #3b82f6;padding:12px 14px;font-size:14px;line-height:1.5">
    <div style="font-weight:600;margin-bottom:6px">Jak funguje přepínání podsítí</div>
    <p style="margin:0 0 8px 0">
        Povolené podsítě omezují provoz vašich zařízení na určitý počet současně aktivních přípojných míst.
        Předpokládá se, že každé přípojné místo se nachází v jiné podsíti.
        IP adresám <strong>mimo</strong> aktivní podsítě bude blokován přístup na internet (formou přesměrování).
    </p>
    <p style="margin:0">
        <span style="color:#27ae60;font-weight:600">✓ zelené</span> = podsíť je aktivní (internet funguje).
        <span style="color:#aaa;font-weight:600">✗ šedé</span> = podsíť je vypnutá.
        Kliknutím na ikonu ji zapnete nebo vypnete.
        <strong>POZOR:</strong> pokud máte limit překročený, zapnutím jedné podsítě se automaticky vypne ta nejstarší aktivní —
        nelze mít zapnutých víc než povoluje limit ({{ $maxCount > 0 ? $maxCount : '∞' }}).
    </p>
</div>

<div class="m-card" style="max-width:520px;margin-bottom:16px">
    <div class="m-card-title">Nastavení</div>
    @if($canEditCount)
    <form method="POST" action="{{ route('allowed_subnets.update_count', $member->id) }}" style="display:flex;align-items:center;gap:8px;padding:6px 0;flex-wrap:wrap">
        @csrf
        @method('PUT')
        <span class="m-field-label">Max. povolených podsítí:</span>
        <input class="m-form-input" type="number" name="allowed_subnets_count" min="0"
               value="{{ $maxCount }}" style="max-width:80px"
               {{ $hasOwnLimit ? '' : 'disabled' }}>
        <label style="display:flex;align-items:center;gap:4px;font-size:14px;cursor:pointer">
            <input type="checkbox" name="use_global" value="1" onchange="this.form.querySelector('input[name=allowed_subnets_count]').disabled=this.checked"
                   {{ $hasOwnLimit ? '' : 'checked' }}>
            Použít globální ({{ $globalCount }})
        </label>
        <button class="m-btn m-btn-primary" type="submit" style="padding:5px 10px;font-size:14px">Uložit</button>
    </form>
    @endif
    <div class="m-field">
        <span class="m-field-label">Zapnutých podsítí</span>
        <span class="m-field-value">
            {{ $enabledCount }}
            @if($maxCount > 0) / {{ $maxCount }} (max)
            @else (neomezeno) @endif
            @if(!$hasOwnLimit && $canEditCount)
                <span style="color:#888;font-size:13px">(globální default)</span>
            @endif
        </span>
    </div>
</div>

@if($canNew && $availableSubnets->count() > 0)
<form method="POST" action="{{ route('allowed_subnets.store', $member->id) }}" style="display:flex;gap:8px;margin-bottom:16px">
    @csrf
    <select class="m-form-select" name="subnet_id" required style="max-width:320px">
        <option value="">— vyberte podsíť —</option>
        @foreach($availableSubnets as $subnet)
            <option value="{{ $subnet->id }}">
                {{ $subnet->name }} ({{ $subnet->network_address }}/{{ $subnet->netmask }})
            </option>
        @endforeach
    </select>
    <button class="m-btn m-btn-success" type="submit">+ Přidat podsíť</button>
    @error('subnet_id') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
</form>
@endif

<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th>Název podsítě</th>
            <th>Adresa sítě</th>
            <th style="width:170px">Rychlost</th>
            <th style="width:180px">Platba</th>
            <th style="width:90px;text-align:center">Stav</th>
            <th style="width:170px">Akce</th>
        </tr>
    </thead>
    <tbody>
        @forelse($allowedSubnets as $as)
        <tr>
            <td>
                @if($canViewSubnetDetail)
                    <a class="m-link" href="{{ route('subnets.show', $as->subnet_id) }}">{{ $as->subnet->name ?? '—' }}</a>
                @else
                    {{ $as->subnet->name ?? '—' }}
                @endif
            </td>
            <td style="font-family:monospace;font-size:14px">
                {{ $as->subnet->network_address ?? '—' }}/{{ $as->subnet->netmask ?? '' }}
            </td>
            <td>
                @if($canEditSpeed)
                <form method="POST" action="{{ route('allowed_subnets.update_speed', $as->id) }}" style="margin:0">
                    @csrf @method('PUT')
                    <select name="speed_class_id" onchange="this.form.submit()" style="font-size:13px;padding:2px 4px;max-width:155px">
                        <option value="">— zdědit ({{ $memberSpeed->name ?? 'člen' }}) —</option>
                        @foreach($speedClasses as $sc)
                        <option value="{{ $sc->id }}" @selected((int)$as->speed_class_id === (int)$sc->id)>{{ $sc->name }}</option>
                        @endforeach
                    </select>
                </form>
                @elseif($as->speedClass)
                    {{ $as->speedClass->name }}
                @else
                    <span style="color:#999">zděděno{{ $memberSpeed ? ': '.$memberSpeed->name : '' }}</span>
                @endif
            </td>
            <td>
                @php
                    // Účtovat lze jen místo s vlastní rychlostí; cena = cena té rychlosti.
                    $ownSpeedPrice = $as->speedClass?->price;
                    $effFee = ($as->charged && $as->speed_class_id) ? ($ownSpeedPrice ?? 0) : null;
                @endphp
                @if($canEditSpeed)
                    @if($as->speed_class_id)
                    <form method="POST" action="{{ route('allowed_subnets.update_billing', $as->id) }}" style="margin:0;display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                        @csrf @method('PUT')
                        <label style="font-size:12px;display:flex;align-items:center;gap:3px">
                            <input type="checkbox" name="charged" value="1" @checked($as->charged) onchange="this.form.submit()"> účtovat
                        </label>
                        @if($as->charged)
                        <span style="font-size:12px;color:#333">{{ $ownSpeedPrice !== null ? number_format((float)$ownSpeedPrice, 0, ',', ' ').' Kč' : 'rychlost bez ceny' }}</span>
                        @endif
                    </form>
                    @else
                    <span style="color:#bbb;font-size:12px" title="Zděděná rychlost se neúčtuje">— (zděděno)</span>
                    @endif
                @else
                    @if($effFee !== null)
                        {{ number_format((float)$effFee, 2, ',', ' ') }} Kč
                    @else
                        <span style="color:#bbb">—</span>
                    @endif
                @endif
            </td>
            <td style="text-align:center">
                @if($as->enabled)
                <span style="color:#27ae60;font-weight:600">✓ Zapnuto</span>
                @else
                <span style="color:#aaa">✗ Vypnuto</span>
                @endif
            </td>
            <td>
                @if($canToggle)
                <form method="POST" action="{{ route('allowed_subnets.toggle', $as->id) }}" style="display:inline">
                    @csrf
                    @if($as->enabled)
                    <button type="submit" style="background:#f0f0f0;color:#555;border:1px solid #ccc;border-radius:4px;padding:4px 10px;cursor:pointer;font-size:13px">Vypnout</button>
                    @else
                    <button type="submit" style="background:#27ae60;color:#fff;border:none;border-radius:4px;padding:4px 10px;cursor:pointer;font-size:13px">Zapnout</button>
                    @endif
                </form>
                @endif
                @if($canDelete)
                <form method="POST" action="{{ route('allowed_subnets.destroy', $as->id) }}" style="display:inline;margin-left:6px"
                      onsubmit="return confirm('Odebrat podsíť {{ addslashes($as->subnet->name ?? '') }}?')">
                    @csrf @method('DELETE')
                    <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;font-size:14px;color:#c0392b">Odebrat</button>
                </form>
                @endif
            </td>
        </tr>
        @empty
        <tr><td colspan="6" style="text-align:center;color:#aaa;padding:2rem">Žádné povolené podsítě.</td></tr>
        @endforelse
    </tbody>
</table>
</div>
</div>
@endsection
