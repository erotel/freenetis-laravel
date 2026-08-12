@extends('layouts.app')
@section('title', 'Členové tarifu')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('fees.index') }}">Tarify</a> &raquo;
    <span>{{ $fee->name ?: ('#' . $fee->id) }}</span>
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Členové s tarifem: {{ $fee->name ?: ('#' . $fee->id) }}</h2></div>
<div class="m-subtitle">
    {{ \App\Models\Fee::typeLabels()[$fee->type_id] ?? $fee->enumType?->value ?? $fee->type_id }}
    · {{ number_format($fee->fee, 2, ',', ' ') }} Kč
    · aktivních přiřazení: {{ $members->count() }}
</div>

<div class="m-actions">
    <a class="m-btn" href="{{ route('fees.index') }}">&larr; Zpět na tarify</a>
</div>

<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:70px">ID</th>
            <th>Jméno</th>
            <th style="width:170px">Typ</th>
            <th style="width:110px">Aktivní od</th>
            <th style="width:110px">Aktivní do</th>
            <th>Poznámka</th>
        </tr>
    </thead>
    <tbody>
        @forelse($members as $m)
        <tr>
            <td>{{ $m->member_id }}</td>
            <td><a class="m-link" href="{{ route('members.show', $m->member_id) }}">{{ $m->name }}</a></td>
            <td>{{ \App\Helpers\MemberType::label((int) $m->type) }}</td>
            <td style="font-size:14px">{{ \Illuminate\Support\Carbon::parse($m->activation_date)->format('d.m.Y') }}</td>
            <td style="font-size:14px">
                @php $to = \Illuminate\Support\Carbon::parse($m->deactivation_date); @endphp
                {{ $to->year < 9999 ? $to->format('d.m.Y') : '∞' }}
            </td>
            <td style="color:#666">{{ $m->comment ?: '—' }}</td>
        </tr>
        @empty
        <tr><td colspan="6" style="text-align:center;color:#aaa;padding:2rem">Tento tarif nemá žádné aktivní členy.</td></tr>
        @endforelse
    </tbody>
</table>
</div>
</div>
@endsection
