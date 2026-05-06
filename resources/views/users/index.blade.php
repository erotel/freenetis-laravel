@extends('layouts.app')
@section('title', 'Uživatelé')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs"><a href="{{ route('users.index') }}">Uživatelé</a></div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Uživatelé</h2></div>
<div class="m-subtitle">Celkem: {{ $users->total() }} záznamů</div>

<div class="m-card" style="margin-bottom:16px;padding:14px 1.25rem">
    <form method="GET" action="{{ route('users.index') }}" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
        @if($memberId)<input type="hidden" name="member_id" value="{{ $memberId }}">@endif
        <div>
            <div class="m-form-label">Hledat</div>
            <input class="m-form-input" style="width:220px" type="text" name="search"
                   value="{{ $search }}" placeholder="Jméno nebo login...">
        </div>
        <div>
            <div class="m-form-label">Na stránku</div>
            <select class="m-form-select" style="width:80px" name="record_per_page" onchange="this.form.submit()">
                @foreach([50,100,150,200,250,300,350,400,450,500] as $n)
                <option value="{{ $n }}" @selected($perPage === $n)>{{ $n }}</option>
                @endforeach
            </select>
        </div>
        <div style="display:flex;gap:6px;padding-bottom:1px">
            <button class="m-btn m-btn-primary" type="submit">Hledat</button>
            @if($search || $memberId)
            <a class="m-btn" href="{{ route('users.index') }}">Zrušit filtr</a>
            @endif
        </div>
        @if(request('sort'))<input type="hidden" name="sort" value="{{ $sort }}">@endif
        @if(request('dir'))<input type="hidden" name="dir" value="{{ $dir }}">@endif
    </form>
</div>

@if($canNew)
<div class="m-actions">
    <a class="m-btn m-btn-success" href="{{ route('users.create') }}">+ Přidat uživatele</a>
</div>
@endif

@php
    $nextDir = fn(string $col) => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    $arrow   = fn(string $col) => $sort === $col ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
    $sortUrl = fn(string $col) => request()->fullUrlWithQuery(['sort' => $col, 'dir' => $nextDir($col), 'page' => 1]);
@endphp

<div style="margin-bottom:8px">{{ $users->links() }}</div>

<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px"><a class="m-link-sm" href="{{ $sortUrl('id') }}">ID{{ $arrow('id') }}</a></th>
            <th style="width:150px"><a class="m-link-sm" href="{{ $sortUrl('login') }}">Login{{ $arrow('login') }}</a></th>
            <th><a class="m-link-sm" href="{{ $sortUrl('surname') }}">Jméno{{ $arrow('surname') }}</a></th>
            <th>Člen</th>
            <th style="width:130px"><a class="m-link-sm" href="{{ $sortUrl('type') }}">Typ{{ $arrow('type') }}</a></th>
            <th style="width:100px">Akce</th>
        </tr>
    </thead>
    <tbody>
        @forelse($users as $user)
        <tr>
            <td>{{ $user->id }}</td>
            <td><a class="m-link" href="{{ route('users.show', $user->id) }}">{{ $user->login }}</a></td>
            <td>{{ $user->full_name }}</td>
            <td>
                @if($user->member)
                    <a class="m-link" href="{{ route('members.show', $user->member_id) }}">{{ $user->member->name }}</a>
                @endif
            </td>
            <td>
                @if($user->type == 1)
                    <span class="m-tag m-tag-amber">Hlavní uživatel</span>
                @else
                    <span class="m-tag m-tag-gray">Uživatel</span>
                @endif
            </td>
            <td>
                <div style="display:flex;gap:6px">
                    <a class="m-link-sm" href="{{ route('users.show', $user->id) }}">Detail</a>
                    @if($canEdit)
                    <a class="m-link-sm" href="{{ route('users.edit', $user->id) }}">Upravit</a>
                    @endif
                    @if($canDelete)
                    <form method="POST" action="{{ route('users.destroy', $user->id) }}" style="display:inline"
                          onsubmit="return confirm('Opravdu smazat uživatele {{ addslashes($user->login) }}?')">
                        @csrf @method('DELETE')
                        <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;font-size:14px;color:#c0392b">Smazat</button>
                    </form>
                    @endif
                </div>
            </td>
        </tr>
        @empty
        <tr><td colspan="6" style="text-align:center;color:#aaa;padding:2rem">Žádní uživatelé nebyli nalezeni.</td></tr>
        @endforelse
    </tbody>
</table>
</div>

<div style="margin-top:12px">{{ $users->links() }}</div>
</div>
@endsection
