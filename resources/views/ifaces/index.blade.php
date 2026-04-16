@extends('layouts.app')
@section('title', 'Seznam rozhraní')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs"><a href="{{ route('ifaces.index') }}">Rozhraní</a></div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Seznam rozhraní</h2></div>
<div class="m-subtitle">Celkem: {{ $ifaces->total() }} záznamů</div>

<div class="m-card" style="margin-bottom:16px;padding:14px 1.25rem">
    <form method="GET" action="{{ route('ifaces.index') }}" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
        @if(request('sort'))<input type="hidden" name="sort" value="{{ $sort }}">@endif
        @if(request('dir'))<input type="hidden" name="dir" value="{{ $dir }}">@endif
        <div>
            <div class="m-form-label">Hledat</div>
            <input class="m-form-input" style="width:220px" type="text" name="search"
                   value="{{ $search }}" placeholder="Název nebo MAC...">
        </div>
        <div>
            <div class="m-form-label">Na stránku</div>
            <select class="m-form-select" style="width:80px" name="record_per_page" onchange="this.form.submit()">
                @foreach([50,100,150,200,250,300] as $n)
                <option value="{{ $n }}" @selected($perPage === $n)>{{ $n }}</option>
                @endforeach
            </select>
        </div>
        <div style="display:flex;gap:6px;padding-bottom:1px">
            <button class="m-btn m-btn-primary" type="submit">Hledat</button>
            @if($search) <a class="m-btn" href="{{ route('ifaces.index') }}">Zrušit filtr</a> @endif
        </div>
    </form>
</div>

@php
    $nextDir = fn(string $col) => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    $arrow   = fn(string $col) => $sort === $col ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
    $sortUrl = fn(string $col) => request()->fullUrlWithQuery(['sort' => $col, 'dir' => $nextDir($col), 'page' => 1]);
@endphp

<div style="margin-bottom:8px">{{ $ifaces->links() }}</div>

<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px"><a class="m-link-sm" href="{{ $sortUrl('id') }}">ID{{ $arrow('id') }}</a></th>
            <th style="width:110px"><a class="m-link-sm" href="{{ $sortUrl('type') }}">Typ{{ $arrow('type') }}</a></th>
            <th><a class="m-link-sm" href="{{ $sortUrl('name') }}">Název{{ $arrow('name') }}</a></th>
            <th style="width:150px">MAC</th>
            <th><a class="m-link-sm" href="{{ $sortUrl('device_id') }}">Zařízení{{ $arrow('device_id') }}</a></th>
            <th>Člen</th>
            <th style="width:60px">Akce</th>
        </tr>
    </thead>
    <tbody>
        @forelse($ifaces as $iface)
        <tr>
            <td>{{ $iface->id }}</td>
            <td>{{ $iface->type_label }}</td>
            <td>{{ $iface->name ?? '—' }}</td>
            <td style="font-family:monospace;font-size:12px">{{ $iface->mac ?? '—' }}</td>
            <td>
                @if($iface->device)
                    <a class="m-link" href="{{ route('devices.show', $iface->device_id) }}">{{ $iface->device->name }}</a>
                @else —
                @endif
            </td>
            <td>
                @if($iface->device?->user?->member)
                    <a class="m-link" href="{{ route('members.show', $iface->device->user->member_id) }}">{{ $iface->device->user->member->name }}</a>
                @else —
                @endif
            </td>
            <td><a class="m-link-sm" href="{{ route('ifaces.show', $iface->id) }}">Detail</a></td>
        </tr>
        @empty
        <tr><td colspan="7" style="text-align:center;color:#aaa;padding:2rem">Žádná rozhraní nebyla nalezena.</td></tr>
        @endforelse
    </tbody>
</table>
</div>

<div style="margin-top:12px">{{ $ifaces->links() }}</div>
</div>
@endsection
