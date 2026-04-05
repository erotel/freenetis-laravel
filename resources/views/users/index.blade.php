@extends('layouts.app')

@section('title', 'Uživatelé')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('users.index') }}">Uživatelé</a>
    </div>
@endsection

@section('content')
    <style>
    .users-table { table-layout: fixed; width: 100%; }
    .users-table .col-id     { width: 60px; }
    .users-table .col-login  { width: 160px; }
    .users-table .col-name   { max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .users-table .col-member { max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .users-table .col-type   { width: 130px; }
    .users-table .col-akce   { width: 80px; }
    </style>

    <h2>Uživatelé</h2>

    <form id="user-search" method="GET" action="{{ route('users.index') }}" style="margin-bottom:1em;">
        <input type="text" name="search" value="{{ $search }}" placeholder="Hledat podle jména/loginu...">
        <button type="submit" form="user-search">Hledat</button>
        @if($search || $memberId)
            <a href="{{ route('users.index') }}">Zrušit filtr</a>
        @endif
        @if($memberId)
            <input type="hidden" name="member_id" value="{{ $memberId }}">
        @endif
    </form>

    @if($canNew)
        <p>
            <a href="{{ route('users.create') }}">
                <img src="{{ asset('media/images/icons/ico_add.gif') }}" alt="Přidat">
                Přidat nového uživatele
            </a>
        </p>
    @endif

    @php
        $nextDir = fn(string $col) => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
        $arrow   = fn(string $col) => $sort === $col ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
        $sortUrl = fn(string $col) => request()->fullUrlWithQuery(['sort' => $col, 'dir' => $nextDir($col), 'page' => 1]);
    @endphp

    <table class="extended users-table" cellspacing="0">
        <thead>
            <tr>
                <th class="col-id"><a href="{{ $sortUrl('id') }}">ID{{ $arrow('id') }}</a></th>
                <th class="col-login"><a href="{{ $sortUrl('login') }}">Login{{ $arrow('login') }}</a></th>
                <th class="col-name"><a href="{{ $sortUrl('surname') }}">Jméno{{ $arrow('surname') }}</a></th>
                <th class="col-member">Člen</th>
                <th class="col-type"><a href="{{ $sortUrl('type') }}">Typ{{ $arrow('type') }}</a></th>
                <th class="col-akce">Akce</th>
            </tr>
        </thead>
        <tbody>
            @forelse($users as $user)
                <tr>
                    <td class="col-id">{{ $user->id }}</td>
                    <td class="col-login"><a href="{{ route('users.show', $user->id) }}">{{ $user->login }}</a></td>
                    <td class="col-name">{{ $user->full_name }}</td>
                    <td class="col-member">
                        @if($user->member)
                            <a href="{{ route('members.show', $user->member_id) }}">{{ $user->member->name }}</a>
                        @endif
                    </td>
                    <td class="col-type">{{ $user->type == 1 ? 'Hlavní uživatel' : 'Uživatel' }}</td>
                    <td class="action col-akce">
                        <a href="{{ route('users.show', $user->id) }}" title="Detail">
                            <img src="{{ asset('media/images/icons/con_info.png') }}" alt="Detail">
                        </a>
                        @if($canEdit)
                            <a href="{{ route('users.edit', $user->id) }}" title="Upravit">
                                <img src="{{ asset('media/images/icons/gtk_edit.png') }}" alt="Upravit">
                            </a>
                        @endif
                        @if($canDelete)
                            <form method="POST" action="{{ route('users.destroy', $user->id) }}" style="display:inline;"
                                  onsubmit="return confirm('Opravdu smazat uživatele {{ addslashes($user->login) }}?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="icon-button" title="Smazat">
                                    <img src="{{ asset('media/images/icons/delete.png') }}" alt="Smazat">
                                </button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">Žádní uživatelé nebyli nalezeni.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="pagination-wrap">
        {{ $users->links() }}
    </div>

    <form method="GET" action="{{ route('users.index') }}" style="margin-top:1em;">
        @if(request('sort'))     <input type="hidden" name="sort"      value="{{ $sort }}"> @endif
        @if(request('dir'))      <input type="hidden" name="dir"       value="{{ $dir }}">  @endif
        @if(request('search'))   <input type="hidden" name="search"    value="{{ $search }}"> @endif
        @if($memberId)           <input type="hidden" name="member_id" value="{{ $memberId }}"> @endif
        Záznamů na stránku:
        <select name="record_per_page" onchange="this.form.submit()">
            @foreach([50, 100, 150, 200, 250, 300, 350, 400, 450, 500] as $n)
                <option value="{{ $n }}" @selected($perPage === $n)>{{ $n }}</option>
            @endforeach
        </select>
    </form>
@endsection
