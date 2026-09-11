@extends('layouts.app')
@section('title', 'Schůze členů')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs"><a href="{{ route('meetings.index') }}">Schůze členů</a></div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Schůze členů</h2></div>
<div class="m-subtitle">Členů s hlasovacím právem (seniorů): <strong>{{ $seniorCount }}</strong></div>

@if($canNew)
<div class="m-actions">
    <a class="m-btn m-btn-success" href="{{ route('meetings.create') }}">+ Nová schůze</a>
</div>
@endif

@if($meetings->isEmpty())
<div class="m-card"><div style="text-align:center;color:#aaa;padding:1.5rem">Žádné schůze.</div></div>
@else
<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead><tr>
        <th>Datum</th><th>Název</th><th>Typ</th><th>Kvórum</th><th>Stav</th><th style="width:70px">Akce</th>
    </tr></thead>
    <tbody>
        @foreach($meetings as $m)
        <tr>
            <td style="white-space:nowrap">{{ $m->held_on?->format('d.m.Y') }}</td>
            <td><a class="m-link" href="{{ route('meetings.show', $m->id) }}">{{ $m->title }}</a></td>
            <td>{{ $m->typeLabel() }}</td>
            <td>{{ $m->quorum_percent }} %</td>
            <td>
                @if($m->status === 'closed')<span class="m-tag">{{ $m->statusLabel() }}</span>
                @elseif($m->status === 'open')<span class="m-tag m-tag-green">{{ $m->statusLabel() }}</span>
                @else<span class="m-tag m-tag-amber">{{ $m->statusLabel() }}</span>@endif
            </td>
            <td><a class="m-link-sm" href="{{ route('meetings.show', $m->id) }}">Prezence</a></td>
        </tr>
        @endforeach
    </tbody>
</table>
</div>
<div style="margin-top:14px">{{ $meetings->links() }}</div>
@endif
</div>
@endsection
