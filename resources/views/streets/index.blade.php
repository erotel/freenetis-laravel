@extends('layouts.app')

@section('title', 'Seznam ulic')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="#">Adresní body</a> &raquo;
        <a href="{{ route('towns.index') }}">Města</a> &raquo;
        <a href="{{ route('streets.index') }}">Ulice</a>
    </div>
@endsection

@section('content')
    <h2>Seznam všech ulic</h2>

    @if($canNew)
        <p>
            <a href="{{ route('streets.create') }}">
                <img src="{{ asset('media/images/icons/ico_add.gif') }}" alt="Přidat">
                Přidat novou ulici
            </a>
        </p>
    @endif

    @php
        $nextDir = fn(string $col) => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
        $arrow   = fn(string $col) => $sort === $col ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
        $sortUrl = fn(string $col) => request()->fullUrlWithQuery(['sort' => $col, 'dir' => $nextDir($col), 'page' => 1]);
    @endphp

    <table class="extended" cellspacing="0">
        <thead>
            <tr>
                <th><a href="{{ $sortUrl('id') }}">ID{{ $arrow('id') }}</a></th>
                <th><a href="{{ $sortUrl('street') }}">Ulice{{ $arrow('street') }}</a></th>
                <th><a href="{{ $sortUrl('town_id') }}">Město{{ $arrow('town_id') }}</a></th>
                <th>Akce</th>
            </tr>
        </thead>
        <tbody>
            @forelse($streets as $street)
                <tr>
                    <td>{{ $street->id }}</td>
                    <td>{{ $street->street }}</td>
                    <td>
                        @if($street->town)
                            <a href="{{ route('towns.show', $street->town_id) }}">{{ $street->town->town }}</a>
                        @endif
                    </td>
                    <td class="action">
                        <a href="{{ route('streets.show', $street->id) }}" title="Detail">
                            <img src="{{ asset('media/images/icons/con_info.png') }}" alt="Detail">
                        </a>
                        @if($canEdit)
                            <a href="{{ route('streets.edit', $street->id) }}" title="Upravit">
                                <img src="{{ asset('media/images/icons/gtk_edit.png') }}" alt="Upravit">
                            </a>
                        @endif
                        @if($canDelete)
                            <form method="POST" action="{{ route('streets.destroy', $street->id) }}" style="display:inline;"
                                  onsubmit="return confirm('Opravdu smazat ulici {{ addslashes($street->street) }}?');">
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
                    <td colspan="4">Žádné ulice nebyly nalezeny.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="pagination-wrap">
        {{ $streets->links() }}
    </div>

    <form method="GET" action="{{ route('streets.index') }}" style="margin-top:1em;">
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
