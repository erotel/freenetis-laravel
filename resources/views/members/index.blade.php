@extends('layouts.app')

@section('title', 'Seznam členů')

@section('menu')
<x-freenetis-menu />
@endsection

@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('members.index') }}">Členové</a>
</div>
@endsection

@section('content')
<div class="m-page">

<div class="m-title-row">
    <h2>Seznam členů</h2>
</div>
<div class="m-subtitle">Celkem: {{ $members->total() }} záznamů</div>

{{-- Filtry --}}
<div class="m-card" style="margin-bottom:16px;padding:14px 1.25rem">
    <form method="GET" action="{{ route('members.index') }}" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
        <div>
            <div class="m-form-label">Hledat</div>
            <input class="m-form-input" style="width:200px" type="text" name="search"
                   value="{{ $search }}" placeholder="Podle jména...">
        </div>
        <div>
            <div class="m-form-label">Typ</div>
            <select class="m-form-select" style="width:160px" name="types" onchange="this.form.submit()">
                <option value="all" @selected($currentTypes === 'all')>— všechny typy —</option>
                <option value="1,3,15,17,90" @selected($currentTypes === '1,3,15,17,90')>Členové</option>
                <option value="2,16,18" @selected($currentTypes === '2,16,18')>Zákazníci</option>
                <option value="17,18" @selected($currentTypes === '17,18')>Čekatelé</option>
                @foreach($memberTypes as $typeId => $typeLabel)
                <option value="{{ $typeId }}" @selected($currentTypes === (string)$typeId)>{{ $typeLabel }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <div class="m-form-label">Stav</div>
            <select class="m-form-select" style="width:140px" name="locked" onchange="this.form.submit()">
                <option value="all">— všechny stavy —</option>
                <option value="0" @selected($currentLocked === '0')>Odemčeni</option>
                <option value="1" @selected($currentLocked === '1')>Zamčeni</option>
            </select>
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
            @if($search || $currentTypes !== 'all' || $currentLocked !== 'all')
            <a class="m-btn" href="{{ route('members.index') }}">Zrušit filtry</a>
            @endif
        </div>
        @if(request('sort'))<input type="hidden" name="sort" value="{{ $sort }}">@endif
        @if(request('dir'))<input type="hidden" name="dir" value="{{ $dir }}">@endif
    </form>
</div>

@if($canNew)
<div class="m-actions">
    <a class="m-btn m-btn-success" href="{{ route('members.create') }}">+ Přidat nového člena</a>
</div>
@endif

@php
    $nextDir = fn(string $col) => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    $arrow   = fn(string $col) => $sort === $col ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
    $sortUrl = fn(string $col) => request()->fullUrlWithQuery(['sort' => $col, 'dir' => $nextDir($col), 'page' => 1]);
@endphp

{{-- Stránkování nahoře --}}
<div style="margin-bottom:8px">{{ $members->links() }}</div>

{{-- Tabulka --}}
<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px"><a class="m-link-sm" href="{{ $sortUrl('id') }}">ID{{ $arrow('id') }}</a></th>
            <th><a class="m-link-sm" href="{{ $sortUrl('name') }}">Jméno{{ $arrow('name') }}</a></th>
            <th style="width:140px"><a class="m-link-sm" href="{{ $sortUrl('type') }}">Typ{{ $arrow('type') }}</a></th>
            <th style="width:120px">Město</th>
            <th style="width:110px">VS</th>
            <th style="width:50px"><a class="m-link-sm" href="{{ $sortUrl('registration') }}">Reg.{{ $arrow('registration') }}</a></th>
            <th style="width:90px">Stav</th>
            <th style="width:70px">Akce</th>
        </tr>
    </thead>
    <tbody>
        @forelse($members as $member)
        <tr>
            <td>{{ $member->id }}</td>
            <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                <a class="m-link" href="{{ route('members.show', $member->id) }}">{{ $member->name }}</a>
            </td>
            <td>
                @php
                    $badgeClass = match($member->type) {
                        2, 18   => 'm-badge-blue',
                        90, 3   => 'm-badge-green',
                        15, 16  => 'm-badge-gray',
                        1,17    => 'm-badge-amber',
                        default => 'm-badge-gray',
                    };
                @endphp
                <span class="m-badge {{ $badgeClass }}" style="font-size:11px">{{ $member->type_label }}</span>
            </td>
            <td>{{ $member->addressPoint?->town?->town ?? '—' }}</td>
            <td style="font-family:monospace;font-size:12px">{{ $member->variable_symbol ?? '—' }}</td>
            <td style="text-align:center">
                @if($member->registration)
                    <span class="m-tag m-tag-green">Ano</span>
                @else
                    <span class="m-tag m-tag-gray">Ne</span>
                @endif
            </td>
            <td>
                @if($member->locked)
                    <span class="m-tag m-tag-red">Zamčen</span>
                @else
                    <span class="m-tag m-tag-green">Odemčen</span>
                @endif
            </td>
            <td>
                <div style="display:flex;gap:6px;align-items:center">
                    <a class="m-link-sm" href="{{ route('members.show', $member->id) }}" title="Detail">Detail</a>
                    @if($canEdit)
                    <a class="m-link-sm" href="{{ route('members.edit', $member->id) }}" title="Upravit">Upravit</a>
                    @endif
                    @if($canDelete)
                    <form method="POST" action="{{ route('members.destroy', $member->id) }}" style="display:inline"
                          onsubmit="return confirm('Opravdu smazat člena {{ addslashes($member->name) }}?')">
                        @csrf @method('DELETE')
                        <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;font-size:12px;color:#c0392b" title="Smazat">Smazat</button>
                    </form>
                    @endif
                </div>
            </td>
        </tr>
        @empty
        <tr><td colspan="8" style="text-align:center;color:#aaa;padding:2rem">Žádní členové nebyli nalezeni.</td></tr>
        @endforelse
    </tbody>
</table>
</div>

<div style="margin-top:12px">{{ $members->links() }}</div>

</div>
@endsection
