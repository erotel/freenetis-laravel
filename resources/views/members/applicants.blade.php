@extends('layouts.app')
@section('title', 'Čekatelé')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs"><a href="{{ route('members.index') }}">Zákazníci</a> &raquo; Čekatelé</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Čekatelé</h2></div>
<div class="m-subtitle">Celkem: {{ $pending->count() }} čekatelů</div>

@if($pending->isEmpty())
<div class="m-card">
    <div style="text-align:center;color:#aaa;padding:2rem">Žádní čekatelé.</div>
</div>
@else
<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px">ID</th>
            <th>Jméno</th>
            <th style="width:160px">Typ</th>
            <th style="width:120px">Datum vstupu</th>
            <th style="width:110px">Přihláška</th>
            <th style="width:120px">Akce</th>
        </tr>
    </thead>
    <tbody>
        @foreach($pending as $m)
        <tr>
            <td>{{ $m->id }}</td>
            <td>
                <a class="m-link" href="{{ route('members.show', $m->id) }}">{{ $m->name }}</a>
            </td>
            <td>
                @if($m->type == 17)
                    <span class="m-badge m-badge-amber" style="font-size:13px">Čekající člen</span>
                @else
                    <span class="m-badge m-badge-blue" style="font-size:13px">Čekající zákazník</span>
                @endif
            </td>
            <td>{{ $m->entrance_date }}</td>
            <td>
                @if($m->registration)
                    <span class="m-tag m-tag-green">✓ podepsána</span>
                @else
                    <span class="m-tag m-tag-red">✗ nepodepsána</span>
                @endif
            </td>
            <td>
                <div style="display:flex;gap:6px">
                    <a class="m-link-sm" href="{{ route('members.show', $m->id) }}">Detail</a>
                    <a class="m-link-sm" href="{{ route('members.edit', $m->id) }}">Upravit</a>
                </div>
            </td>
        </tr>
        @endforeach
    </tbody>
</table>
</div>
@endif
</div>
@endsection
