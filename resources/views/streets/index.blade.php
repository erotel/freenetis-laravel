@extends('layouts.app')
@section('title', 'Seznam ulic')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('towns.index') }}">Města</a> &raquo;
    <a href="{{ route('streets.index') }}">Ulice</a>
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Seznam ulic</h2></div>
<div class="m-subtitle">Celkem: {{ $streets->total() }} záznamů</div>

@if($canNew)
<div class="m-actions">
    <a class="m-btn m-btn-success" href="{{ route('streets.create') }}">+ Přidat novou ulici</a>
</div>
@endif

@php
    $nextDir = fn(string $col) => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    $arrow   = fn(string $col) => $sort === $col ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
    $sortUrl = fn(string $col) => request()->fullUrlWithQuery(['sort' => $col, 'dir' => $nextDir($col), 'page' => 1]);
@endphp

<div style="margin-bottom:8px">{{ $streets->links() }}</div>

<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px"><a class="m-link-sm" href="{{ $sortUrl('id') }}">ID{{ $arrow('id') }}</a></th>
            <th><a class="m-link-sm" href="{{ $sortUrl('street') }}">Ulice{{ $arrow('street') }}</a></th>
            <th><a class="m-link-sm" href="{{ $sortUrl('town_id') }}">Město{{ $arrow('town_id') }}</a></th>
            <th style="width:110px">Akce</th>
        </tr>
    </thead>
    <tbody>
        @forelse($streets as $street)
        <tr>
            <td>{{ $street->id }}</td>
            <td>{{ $street->street }}</td>
            <td>
                @if($street->town)
                    <a class="m-link" href="{{ route('towns.show', $street->town_id) }}">{{ $street->town->town }}</a>
                @endif
            </td>
            <td>
                <div style="display:flex;gap:6px">
                    <a class="m-link-sm" href="{{ route('streets.show', $street->id) }}">Detail</a>
                    @if($canEdit)
                    <a class="m-link-sm" href="{{ route('streets.edit', $street->id) }}">Upravit</a>
                    @endif
                    @if($canDelete)
                    <form method="POST" action="{{ route('streets.destroy', $street->id) }}" style="display:inline"
                          onsubmit="return confirm('Opravdu smazat ulici {{ addslashes($street->street) }}?')">
                        @csrf @method('DELETE')
                        <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;font-size:12px;color:#c0392b">Smazat</button>
                    </form>
                    @endif
                </div>
            </td>
        </tr>
        @empty
        <tr><td colspan="4" style="text-align:center;color:#aaa;padding:2rem">Žádné ulice nebyly nalezeny.</td></tr>
        @endforelse
    </tbody>
</table>
</div>

<div style="margin-top:4px;display:flex;align-items:center;gap:10px;justify-content:space-between">
    <div style="margin-top:8px">{{ $streets->links() }}</div>
    <form method="GET" action="{{ route('streets.index') }}" style="display:flex;align-items:center;gap:6px;font-size:13px;margin-top:8px">
        @if(request('sort'))<input type="hidden" name="sort" value="{{ $sort }}">@endif
        @if(request('dir'))<input type="hidden" name="dir" value="{{ $dir }}">@endif
        <span>Na stránku:</span>
        <select class="m-form-select" style="width:70px" name="record_per_page" onchange="this.form.submit()">
            @foreach([50,100,150,200,250,300,350,400,450,500] as $n)
            <option value="{{ $n }}" @selected($perPage === $n)>{{ $n }}</option>
            @endforeach
        </select>
    </form>
</div>
</div>
@endsection
