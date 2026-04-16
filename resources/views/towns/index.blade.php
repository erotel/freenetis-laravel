@extends('layouts.app')
@section('title', 'Seznam měst')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('towns.index') }}">Města</a>
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Seznam měst</h2></div>
<div class="m-subtitle">Celkem: {{ $towns->total() }} záznamů</div>

@if($canNew)
<div class="m-actions">
    <a class="m-btn m-btn-success" href="{{ route('towns.create') }}">+ Přidat nové město</a>
</div>
@endif

@php
    $nextDir = fn(string $col) => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    $arrow   = fn(string $col) => $sort === $col ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
    $sortUrl = fn(string $col) => request()->fullUrlWithQuery(['sort' => $col, 'dir' => $nextDir($col), 'page' => 1]);
@endphp

<div style="margin-bottom:8px">{{ $towns->links() }}</div>

<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px"><a class="m-link-sm" href="{{ $sortUrl('id') }}">ID{{ $arrow('id') }}</a></th>
            <th><a class="m-link-sm" href="{{ $sortUrl('town') }}">Město{{ $arrow('town') }}</a></th>
            <th><a class="m-link-sm" href="{{ $sortUrl('quarter') }}">Čtvrť{{ $arrow('quarter') }}</a></th>
            <th style="width:80px"><a class="m-link-sm" href="{{ $sortUrl('zip_code') }}">PSČ{{ $arrow('zip_code') }}</a></th>
            <th style="width:110px">Akce</th>
        </tr>
    </thead>
    <tbody>
        @forelse($towns as $town)
        <tr>
            <td>{{ $town->id }}</td>
            <td>{{ $town->town }}</td>
            <td>{{ $town->quarter }}</td>
            <td>{{ $town->zip_code }}</td>
            <td>
                <div style="display:flex;gap:6px">
                    <a class="m-link-sm" href="{{ route('towns.show', $town->id) }}">Detail</a>
                    @if($canEdit)
                    <a class="m-link-sm" href="{{ route('towns.edit', $town->id) }}">Upravit</a>
                    @endif
                    @if($canDelete)
                    <form method="POST" action="{{ route('towns.destroy', $town->id) }}" style="display:inline"
                          onsubmit="return confirm('Opravdu smazat město {{ addslashes($town->town) }}?')">
                        @csrf @method('DELETE')
                        <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;font-size:12px;color:#c0392b">Smazat</button>
                    </form>
                    @endif
                </div>
            </td>
        </tr>
        @empty
        <tr><td colspan="5" style="text-align:center;color:#aaa;padding:2rem">Žádná města nebyla nalezena.</td></tr>
        @endforelse
    </tbody>
</table>
</div>

<div style="margin-top:4px;display:flex;align-items:center;gap:10px;justify-content:space-between">
    <div style="margin-top:8px">{{ $towns->links() }}</div>
    <form method="GET" action="{{ route('towns.index') }}" style="display:flex;align-items:center;gap:6px;font-size:13px;margin-top:8px">
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
