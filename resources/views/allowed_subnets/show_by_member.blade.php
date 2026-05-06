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
    $maxCount = $member->allowed_subnets_count;
@endphp

<div class="m-card" style="max-width:420px;margin-bottom:16px">
    <div class="m-card-title">Nastavení</div>
    <form method="POST" action="{{ route('allowed_subnets.update_count', $member->id) }}" style="display:flex;align-items:center;gap:8px;padding:6px 0">
        @csrf
        @method('PUT')
        <span class="m-field-label">Max. povolených podsítí:</span>
        <input class="m-form-input" type="number" name="allowed_subnets_count" min="0"
               value="{{ $member->allowed_subnets_count }}" style="max-width:80px">
        <button class="m-btn m-btn-primary" type="submit" style="padding:5px 10px;font-size:14px">Uložit</button>
    </form>
    <div class="m-field">
        <span class="m-field-label">Zapnutých podsítí</span>
        <span class="m-field-value">
            {{ $enabledCount }}
            @if($maxCount > 0) / {{ $maxCount }} (max)
            @else (neomezeno) @endif
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
            <th style="width:80px;text-align:center">Zapnuto</th>
            <th style="width:70px">Akce</th>
        </tr>
    </thead>
    <tbody>
        @forelse($allowedSubnets as $as)
        <tr>
            <td><a class="m-link" href="{{ route('subnets.show', $as->subnet_id) }}">{{ $as->subnet->name ?? '—' }}</a></td>
            <td style="font-family:monospace;font-size:14px">
                {{ $as->subnet->network_address ?? '—' }}/{{ $as->subnet->netmask ?? '' }}
            </td>
            <td style="text-align:center">
                @if($canEdit)
                <form method="POST" action="{{ route('allowed_subnets.toggle', $as->id) }}" style="display:inline">
                    @csrf
                    <button type="submit" style="border:none;background:none;cursor:pointer;padding:0;font-size:19px"
                            title="{{ $as->enabled ? 'Zapnuto — kliknutím vypnout' : 'Vypnuto — kliknutím zapnout' }}">
                        <span style="color:{{ $as->enabled ? '#27ae60' : '#ddd' }}">{{ $as->enabled ? '✓' : '✗' }}</span>
                    </button>
                </form>
                @else
                <span style="color:{{ $as->enabled ? '#27ae60' : '#ddd' }}">{{ $as->enabled ? '✓' : '✗' }}</span>
                @endif
            </td>
            <td>
                @if($canDelete)
                <form method="POST" action="{{ route('allowed_subnets.destroy', $as->id) }}" style="display:inline"
                      onsubmit="return confirm('Odebrat podsíť {{ addslashes($as->subnet->name ?? '') }}?')">
                    @csrf @method('DELETE')
                    <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;font-size:14px;color:#c0392b">Odebrat</button>
                </form>
                @endif
            </td>
        </tr>
        @empty
        <tr><td colspan="4" style="text-align:center;color:#aaa;padding:2rem">Žádné povolené podsítě.</td></tr>
        @endforelse
    </tbody>
</table>
</div>
</div>
@endsection
