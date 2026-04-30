@extends('layouts.app')
@section('title', 'SMTP výjimky')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs"><a href="{{ route('smtp-exceptions.index') }}">SMTP výjimky</a></div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>SMTP výjimky (port 25 povolen)</h2></div>

@if($canEdit)
<div class="m-actions">
    <a class="m-btn m-btn-success" href="{{ route('smtp-exceptions.create') }}">+ Přidat SMTP výjimku</a>
</div>
@endif

<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px">ID</th>
            <th>IP adresa</th>
            <th>Přidal</th>
            <th style="width:120px">Datum</th>
            @if($canEdit)<th style="width:90px">Akce</th>@endif
        </tr>
    </thead>
    <tbody>
        @forelse($rows as $row)
        <tr>
            <td>{{ $row->id }}</td>
            <td style="font-family:monospace;font-size:12px">{{ $row->intip }}</td>
            <td>{{ $row->user }}</td>
            <td style="font-size:12px">{{ $row->datum?->format('d.m.Y') ?? '—' }}</td>
            @if($canEdit)
            <td>
                <div style="display:flex;gap:6px">
                    <a class="m-link-sm" href="{{ route('smtp-exceptions.edit', $row->id) }}">Upravit</a>
                    <a class="m-link-sm" style="color:#c0392b" href="{{ route('smtp-exceptions.destroy', $row->id) }}"
                       onclick="return confirm('Opravdu smazat SMTP výjimku #{{ $row->id }} ({{ $row->intip }})?')">Smazat</a>
                </div>
            </td>
            @endif
        </tr>
        @empty
        <tr><td colspan="{{ $canEdit ? 5 : 4 }}" style="text-align:center;color:#aaa;padding:2rem">Žádné záznamy.</td></tr>
        @endforelse
    </tbody>
</table>
</div>
</div>
@endsection
