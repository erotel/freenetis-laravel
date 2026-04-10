@extends('layouts.app')

@section('title', 'Seznam měst')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="#">Adresní body</a> &raquo;
        <a href="{{ route('towns.index') }}">Města</a> &raquo;
        <a href="#">Ulice</a>
    </div>
@endsection

@section('content')
    <h2>Seznam všech měst</h2>

    @if($canNew)
        <p>
            <a href="{{ route('towns.create') }}">
                <img src="{{ asset('media/images/icons/ico_add.gif') }}" alt="Přidat">
                Přidat nové město
            </a>
        </p>
    @endif

    @php
        $nextDir = fn(string $col) => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
        $arrow   = fn(string $col) => $sort === $col ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
        $sortUrl = fn(string $col) => request()->fullUrlWithQuery(['sort' => $col, 'dir' => $nextDir($col), 'page' => 1]);
    @endphp

    {{ $towns->links() }}
    <table class="extended" cellspacing="0">
        <thead>
            <tr>
                <th><a href="{{ $sortUrl('id') }}">ID{{ $arrow('id') }}</a></th>
                <th><a href="{{ $sortUrl('town') }}">Město{{ $arrow('town') }}</a></th>
                <th><a href="{{ $sortUrl('quarter') }}">Čtvrť{{ $arrow('quarter') }}</a></th>
                <th><a href="{{ $sortUrl('zip_code') }}">PSČ{{ $arrow('zip_code') }}</a></th>
                <th>Akce</th>
            </tr>
        </thead>
        <tbody>
            @forelse($towns as $town)
                <tr>
                    <td>{{ $town->id }}</td>
                    <td>{{ $town->town }}</td>
                    <td>{{ $town->quarter }}</td>
                    <td>{{ $town->zip_code }}</td>
                    <td class="action">
                        <a href="{{ route('towns.show', $town->id) }}" title="Detail">
                            <img src="{{ asset('media/images/icons/con_info.png') }}" alt="Detail">
                        </a>
                        @if($canEdit)
                            <a href="{{ route('towns.edit', $town->id) }}" title="Upravit">
                                <img src="{{ asset('media/images/icons/gtk_edit.png') }}" alt="Upravit">
                            </a>
                        @endif
                        @if($canDelete)
                            <form method="POST" action="{{ route('towns.destroy', $town->id) }}" style="display:inline;"
                                  onsubmit="return confirm('Opravdu smazat město {{ addslashes($town->town) }}?');">
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
                    <td colspan="5">Žádná města nebyla nalezena.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="pagination-wrap">
        {{ $towns->links() }}
    </div>

    <form method="GET" action="{{ route('towns.index') }}" style="margin-top:1em;">
        @if(request('sort')) <input type="hidden" name="sort" value="{{ $sort }}"> @endif
        @if(request('dir'))  <input type="hidden" name="dir"  value="{{ $dir }}">  @endif
        Záznamů na stránku:
        <select name="record_per_page" onchange="this.form.submit()">
            @foreach([50, 100, 150, 200, 250, 300, 350, 400, 450, 500] as $n)
                <option value="{{ $n }}" @selected($perPage === $n)>{{ $n }}</option>
            @endforeach
        </select>
    </form>
@endsection
