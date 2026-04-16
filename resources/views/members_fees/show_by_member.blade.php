@extends('layouts.app')
@section('title', 'Tarify člena: ' . $member->name)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('members.index') }}">Členové</a> &raquo;
    <a href="{{ route('members.show', $member->id) }}">{{ $member->name }}</a> &raquo;
    Tarify
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Tarify člena: {{ $member->name }}</h2></div>

<div class="m-actions">
    <a class="m-btn" href="{{ route('members.show', $member->id) }}">&larr; Profil člena</a>
</div>

@foreach($typeLabels as $typeId => $typeLabel)
@php
    $rows        = $memberFees->get($typeId, collect());
    $defaults    = $defaultFees->get($typeId, collect());
    $showDefault = $rows->isEmpty() && $defaults->isNotEmpty();
@endphp

<div class="m-section">
    {{ $typeLabel }}
    @if($canAdd)
    <a class="m-link-sm" href="{{ route('members_fees.create', ['memberId' => $member->id, 'type_id' => $typeId]) }}" style="margin-left:10px">+ Přidat tarif</a>
    @endif
</div>

<div class="m-card" style="padding:0;overflow-x:auto;margin-bottom:16px">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px">ID</th>
            <th>Název</th>
            <th style="width:110px;text-align:right">Poplatek</th>
            <th style="width:100px">Aktivace</th>
            <th style="width:100px">Deaktivace</th>
            <th style="width:80px">Stav</th>
            <th>Komentář</th>
            <th style="width:80px">Akce</th>
        </tr>
    </thead>
    <tbody>
        @forelse($rows as $mf)
        @php $isActive = $mf->activation_date <= today() && ($mf->deactivation_date === null || $mf->deactivation_date >= today()); @endphp
        <tr>
            <td>{{ $mf->id }}</td>
            <td>{{ $mf->fee->name ?: '—' }}</td>
            <td style="text-align:right;font-family:monospace;font-size:12px">{{ number_format($mf->fee->fee, 2, ',', ' ') }} Kč</td>
            <td style="font-size:12px">{{ $mf->activation_date?->format('d.m.Y') }}</td>
            <td style="font-size:12px">{{ $mf->deactivation_date && $mf->deactivation_date->year < 9999 ? $mf->deactivation_date->format('d.m.Y') : '∞' }}</td>
            <td><span class="m-tag {{ $isActive ? 'm-tag-green' : 'm-tag-gray' }}">{{ $isActive ? 'Aktivní' : 'Neaktivní' }}</span></td>
            <td style="font-size:12px">{{ $mf->comment ?: '—' }}</td>
            <td>
                <div style="display:flex;gap:6px">
                    @if($canEdit) <a class="m-link-sm" href="{{ route('members_fees.edit', $mf->id) }}">Upravit</a> @endif
                    @if($canDelete)
                    <form method="POST" action="{{ route('members_fees.destroy', $mf->id) }}" style="display:inline"
                          onsubmit="return confirm('Odebrat tarif?')">
                        @csrf @method('DELETE')
                        <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;font-size:12px;color:#c0392b">Odebrat</button>
                    </form>
                    @endif
                </div>
            </td>
        </tr>
        @empty
            @if($showDefault)
                @foreach($defaults as $mf)
                @php $isActive = $mf->activation_date <= today() && ($mf->deactivation_date === null || $mf->deactivation_date >= today()); @endphp
                <tr style="opacity:.65">
                    <td>{{ $mf->id }}</td>
                    <td>{{ $mf->fee->name ?: '—' }} <span class="m-tag m-tag-gray">výchozí</span></td>
                    <td style="text-align:right;font-family:monospace;font-size:12px">{{ number_format($mf->fee->fee, 2, ',', ' ') }} Kč</td>
                    <td style="font-size:12px">{{ $mf->activation_date?->format('d.m.Y') }}</td>
                    <td style="font-size:12px">{{ $mf->deactivation_date && $mf->deactivation_date->year < 9999 ? $mf->deactivation_date->format('d.m.Y') : '∞' }}</td>
                    <td><span class="m-tag {{ $isActive ? 'm-tag-green' : 'm-tag-gray' }}">{{ $isActive ? 'Aktivní' : 'Neaktivní' }}</span></td>
                    <td style="font-size:12px">{{ $mf->comment ?: '—' }}</td>
                    <td>—</td>
                </tr>
                @endforeach
            @else
                <tr><td colspan="8" style="text-align:center;color:#aaa;padding:1.5rem">Žádné tarify tohoto typu.</td></tr>
            @endif
        @endforelse
    </tbody>
</table>
</div>

@endforeach
</div>
@endsection
