@extends('layouts.app')
@section('title', 'Veřejné porty')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs"><a href="{{ route('public-port-forwards.index') }}">Veřejné porty</a></div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Veřejné porty (port forwarding)</h2></div>

@if($canEdit)
<div class="m-actions">
    <a class="m-btn m-btn-success" href="{{ route('public-port-forwards.create') }}">+ Přidat port forward</a>
</div>
@endif

<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px">ID</th>
            <th style="width:70px">Protokol</th>
            <th>Veřejná IP</th>
            <th style="width:120px">Veřejné porty</th>
            <th>Privátní IP</th>
            <th style="width:120px">Privátní porty</th>
            <th>Člen</th>
            <th>Poslední změna</th>
            @if($canEdit)<th style="width:90px">Akce</th>@endif
        </tr>
    </thead>
    <tbody>
        @forelse($rows as $row)
        @php
            $mod = $row->modified ? \Carbon\Carbon::parse($row->modified) : null;
            $cre = $row->created  ? \Carbon\Carbon::parse($row->created)  : null;
            $useModified = $mod && $mod->year >= 2000;
            $useCreated  = $cre && $cre->year >= 2000;
            $changeDate  = $useModified ? $mod->format('d.m.Y H:i') : ($useCreated ? $cre->format('d.m.Y H:i') : '—');
            $changeName  = $useModified ? ($row->modified_by_name ?: '—') : ($useCreated ? ($row->created_by_name ?: '—') : null);
        @endphp
        <tr>
            <td>{{ $row->id }}</td>
            <td><span class="m-tag m-tag-gray" style="font-size:11px">{{ strtoupper($row->protocol) }}</span></td>
            <td style="font-family:monospace;font-size:12px">{{ $row->public_ip }}</td>
            <td style="font-family:monospace;font-size:12px">
                @if($row->public_port_from == $row->public_port_to)
                    {{ $row->public_port_from }}
                @else
                    {{ $row->public_port_from }}–{{ $row->public_port_to }}
                @endif
            </td>
            <td style="font-family:monospace;font-size:12px">{{ $row->private_ip }}</td>
            <td style="font-family:monospace;font-size:12px">
                @if($row->private_port_from == $row->private_port_to)
                    {{ $row->private_port_from }}
                @else
                    {{ $row->private_port_from }}–{{ $row->private_port_to }}
                @endif
            </td>
            <td>{{ $row->owner_member_name ?: '—' }}</td>
            <td style="font-size:12px">{{ $changeDate }}{{ $changeName ? ' (' . $changeName . ')' : '' }}</td>
            @if($canEdit)
            <td>
                <div style="display:flex;gap:6px">
                    <a class="m-link-sm" href="{{ route('public-port-forwards.edit', $row->id) }}">Upravit</a>
                    <a class="m-link-sm" style="color:#c0392b" href="{{ route('public-port-forwards.destroy', $row->id) }}"
                       onclick="return confirm('Opravdu smazat port forward #{{ $row->id }}?')">Smazat</a>
                </div>
            </td>
            @endif
        </tr>
        @empty
        <tr><td colspan="{{ $canEdit ? 9 : 8 }}" style="text-align:center;color:#aaa;padding:2rem">Žádné záznamy.</td></tr>
        @endforelse
    </tbody>
</table>
</div>
</div>
@endsection
