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
    <style>
    .members-table { table-layout: fixed; width: 100%; }
    .members-table .col-name {
        max-width: 200px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .members-table .col-id    { width: 50px; }
    .members-table .col-type  { width: 140px; }
    .members-table .col-town  { width: 120px; }
    .members-table .col-vs    { width: 110px; }
    .members-table .col-reg   { width: 40px; }
    .members-table .col-stav  { width: 80px; }
    .members-table .col-akce  { width: 70px; }
    </style>

    <h2>Seznam všech členů</h2>

    <form id="member-search" method="GET" action="{{ route('members.index') }}" style="margin-bottom:0.5em;">
        <input type="text" name="search" value="{{ $search }}" placeholder="Hledat podle jména...">
        <button type="submit">Hledat</button>
        @if($search || $currentTypes !== 'all' || $currentLocked !== 'all')
            <a href="{{ route('members.index') }}">Zrušit filtry</a>
        @endif
    </form>

    <form method="GET" action="{{ route('members.index') }}" style="display:inline-flex; gap:8px; margin-bottom:10px; flex-wrap:wrap;">
        @if($search)<input type="hidden" name="search" value="{{ $search }}">@endif
        <select name="types" onchange="this.form.submit()">
            <option value="all" @selected($currentTypes === 'all')>— všechny typy —</option>
            <option value="1,3,15,17,90" @selected($currentTypes === '1,3,15,17,90')>Členové</option>
            <option value="2,16,18" @selected($currentTypes === '2,16,18')>Zákazníci</option>
            <option value="17,18" @selected($currentTypes === '17,18')>Čekatelé</option>
            @foreach($memberTypes as $typeId => $typeLabel)
                <option value="{{ $typeId }}" @selected($currentTypes === (string)$typeId)>{{ $typeLabel }}</option>
            @endforeach
        </select>
        <select name="locked" onchange="this.form.submit()">
            <option value="all">— všechny stavy —</option>
            <option value="0" @selected($currentLocked === '0')>Odemčeni</option>
            <option value="1" @selected($currentLocked === '1')>Zamčeni</option>
        </select>
    </form>

    @if($canNew)
        <p>
            <a href="{{ route('members.create') }}">
                <img src="{{ asset('media/images/icons/ico_add.gif') }}" alt="Přidat">
                Přidat nového člena
            </a>
        </p>
    @endif

    @php
        $nextDir = fn(string $col) => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
        $arrow   = fn(string $col) => $sort === $col ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
        $sortUrl = fn(string $col) => request()->fullUrlWithQuery(['sort' => $col, 'dir' => $nextDir($col), 'page' => 1]);
    @endphp

    {{ $members->links() }}
    <table class="extended members-table" cellspacing="0">
        <thead>
            <tr>
                <th class="col-id"><a href="{{ $sortUrl('id') }}">ID{{ $arrow('id') }}</a></th>
                <th class="col-name"><a href="{{ $sortUrl('name') }}">Jméno{{ $arrow('name') }}</a></th>
                <th class="col-type"><a href="{{ $sortUrl('type') }}">Typ člena{{ $arrow('type') }}</a></th>
                <th class="col-town">Město</th>
                <th class="col-vs">Variabilní symbol</th>
                <th class="col-reg"><a href="{{ $sortUrl('registration') }}">Reg.{{ $arrow('registration') }}</a></th>
                <th class="col-stav">Stav</th>
                <th class="col-akce">Akce</th>
            </tr>
        </thead>
        <tbody>
            @forelse($members as $member)
                <tr>
                    <td class="col-id">{{ $member->id }}</td>
                    <td class="col-name"><a href="{{ route('members.show', $member->id) }}">{{ $member->name }}</a></td>
                    <td class="col-type">{{ $member->type_label }}</td>
                    <td class="col-town">{{ $member->addressPoint?->town?->town ?? '—' }}</td>
                    <td class="col-vs">{{ $member->variable_symbol }}</td>
                    <td class="col-reg">{{ $member->registration ? 'Ano' : 'Ne' }}</td>
                    <td class="col-stav" style="color:{{ $member->locked ? 'red' : 'green' }}">
                        {{ $member->locked ? 'Zamčen' : 'Odemčen' }}
                    </td>
                    <td class="action col-akce">
                        <a href="{{ route('members.show', $member->id) }}" title="Detail">
                            <img src="{{ asset('media/images/icons/con_info.png') }}" alt="Detail">
                        </a>
                        @if($canEdit)
                            <a href="{{ route('members.edit', $member->id) }}" title="Upravit">
                                <img src="{{ asset('media/images/icons/gtk_edit.png') }}" alt="Upravit">
                            </a>
                        @endif
                        @if($canDelete)
                            <form method="POST" action="{{ route('members.destroy', $member->id) }}" style="display:inline;"
                                  onsubmit="return confirm('Opravdu smazat člena {{ addslashes($member->name) }}?');">
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
                    <td colspan="8">Žádní členové nebyli nalezeni.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="pagination-wrap">
        {{ $members->links() }}
    </div>

    <form method="GET" action="{{ route('members.index') }}" style="margin-top:1em;">
        @if(request('sort'))   <input type="hidden" name="sort"   value="{{ $sort }}"> @endif
        @if(request('dir'))    <input type="hidden" name="dir"    value="{{ $dir }}">  @endif
        @if(request('search')) <input type="hidden" name="search" value="{{ $search }}"> @endif
        @if($currentTypes !== 'all') <input type="hidden" name="types" value="{{ $currentTypes }}"> @endif
        @if($currentLocked !== 'all') <input type="hidden" name="locked" value="{{ $currentLocked }}"> @endif
        Záznamů na stránku:
        <select name="record_per_page" onchange="this.form.submit()">
            @foreach([50, 100, 150, 200, 250, 300, 350, 400, 450, 500] as $n)
                <option value="{{ $n }}" @selected($perPage === $n)>{{ $n }}</option>
            @endforeach
        </select>
    </form>
@endsection
