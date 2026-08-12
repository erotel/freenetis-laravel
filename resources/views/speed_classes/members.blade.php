@extends('layouts.app')
@section('title', 'Členové třídy rychlosti')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('speed_classes.index') }}">Třídy rychlosti</a> &raquo;
    <span>{{ $speedClass->name }}</span>
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Členové s rychlostí: {{ $speedClass->name }}</h2></div>
<div class="m-subtitle">
    @if($speedClass->price !== null){{ number_format((float) $speedClass->price, 2, ',', ' ') }} Kč · @endif
    členů: {{ $members->count() }}
</div>

<div class="m-actions">
    <a class="m-btn" href="{{ route('speed_classes.index') }}">&larr; Zpět na třídy rychlosti</a>
</div>

<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:70px">ID</th>
            <th>Jméno</th>
            <th style="width:170px">Typ</th>
        </tr>
    </thead>
    <tbody>
        @forelse($members as $m)
        <tr>
            <td>{{ $m->id }}</td>
            <td><a class="m-link" href="{{ route('members.show', $m->id) }}">{{ $m->name }}</a></td>
            <td>{{ \App\Helpers\MemberType::label((int) $m->type) }}</td>
        </tr>
        @empty
        <tr><td colspan="3" style="text-align:center;color:#aaa;padding:2rem">Žádní členové s touto rychlostí.</td></tr>
        @endforelse
    </tbody>
</table>
</div>
</div>
@endsection
