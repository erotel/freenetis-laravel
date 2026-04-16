@extends('layouts.app')
@section('title', 'Veřejné IP (1:1 NAT)')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs"><a href="{{ route('public-ip-nat.index') }}">Veřejné IP (1:1 NAT)</a></div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Veřejné IP (1:1 NAT)</h2></div>

<div class="m-card" style="margin-bottom:16px;padding:14px 1.25rem">
    <form method="GET" action="{{ route('public-ip-nat.index') }}" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">
        <div>
            <div class="m-form-label">Stav</div>
            <select class="m-form-select" style="width:120px" name="enabled" onchange="this.form.submit()">
                <option value="all" @selected($enabled === 'all')>— vše —</option>
                <option value="1" @selected($enabled === '1')>Aktivní</option>
                <option value="0" @selected($enabled === '0')>Neaktivní</option>
            </select>
        </div>
        <div>
            <div class="m-form-label">Hledat</div>
            <input class="m-form-input" type="text" name="q" value="{{ $q }}" placeholder="IP, člen..." style="width:180px">
        </div>
        <div style="display:flex;gap:6px;padding-bottom:1px">
            <button class="m-btn m-btn-primary" type="submit">Hledat</button>
            @if($q || $enabled !== 'all') <a class="m-btn" href="{{ route('public-ip-nat.index') }}">Zrušit filtr</a> @endif
        </div>
    </form>
</div>

<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px">ID</th>
            <th>Veřejná IP</th>
            <th>Privátní IP</th>
            <th>Člen</th>
            <th>Poslední změna</th>
            @if($canEdit)<th style="width:130px">Akce</th>@endif
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
            <td style="font-family:monospace;font-size:12px">{{ $row->public_ip }}</td>
            <td style="font-family:monospace;font-size:12px">{{ $row->private_ip ?: '—' }}</td>
            <td>{{ $row->owner_member_name ?: '—' }}</td>
            <td style="font-size:12px">{{ $changeDate }}{{ $changeName ? ' (' . $changeName . ')' : '' }}</td>
            @if($canEdit)
            <td>
                <div style="display:flex;gap:6px">
                    <a class="m-link-sm" href="{{ route('public-ip-nat.edit', $row->id) }}">Upravit</a>
                    @if($row->private_ip)
                    <a class="m-link-sm" style="color:#c0392b" href="{{ route('public-ip-nat.clear', $row->id) }}"
                       onclick="return confirm('Opravdu vymazat mapování pro {{ $row->public_ip }}?')">Vymazat</a>
                    @endif
                </div>
            </td>
            @endif
        </tr>
        @empty
        <tr><td colspan="{{ $canEdit ? 6 : 5 }}" style="text-align:center;color:#aaa;padding:2rem">Žádné záznamy.</td></tr>
        @endforelse
    </tbody>
</table>
</div>
</div>
@endsection
