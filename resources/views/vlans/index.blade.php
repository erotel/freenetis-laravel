@extends('layouts.app')

@section('title', 'VLANy')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('vlans.index') }}">VLANy</a>
    </div>
@endsection

@section('content')
    <h2>Seznam VLANů</h2>

    @if($canNew)
        <p>
            <a href="{{ route('vlans.create') }}">
                <img src="{{ asset('media/images/icons/ico_add.gif') }}" alt="Přidat">
                Přidat VLAN
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
                <th><a href="{{ $sortUrl('name') }}">Název{{ $arrow('name') }}</a></th>
                <th><a href="{{ $sortUrl('tag_802_1q') }}">802.1Q tag{{ $arrow('tag_802_1q') }}</a></th>
                <th>Komentář</th>
                <th>Počet rozhraní</th>
                <th>Akce</th>
            </tr>
        </thead>
        <tbody>
            @forelse($vlans as $vlan)
                <tr>
                    <td>{{ $vlan->id }}</td>
                    <td><a href="{{ route('vlans.show', $vlan->id) }}">{{ $vlan->name ?: '—' }}</a></td>
                    <td>{{ $vlan->tag_802_1q }}</td>
                    <td>{{ $vlan->comment ?: '—' }}</td>
                    <td>{{ $vlan->ifaces_count }}</td>
                    <td>
                        <a href="{{ route('vlans.show', $vlan->id) }}" title="Detail">
                            <img src="{{ asset('media/images/icons/con_info.png') }}" alt="Detail">
                        </a>
                        @if($canEdit)
                            <a href="{{ route('vlans.edit', $vlan->id) }}" title="Upravit">
                                <img src="{{ asset('media/images/icons/gtk_edit.png') }}" alt="Upravit">
                            </a>
                        @endif
                        @if($canDelete && $vlan->tag_802_1q !== \App\Models\Vlan::DEFAULT_VLAN_TAG)
                            <form method="POST" action="{{ route('vlans.destroy', $vlan->id) }}" style="display:inline;"
                                  onsubmit="return confirm('Opravdu smazat VLAN {{ addslashes((string)$vlan) }}?');">
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
                    <td colspan="6">Žádné VLANy nebyly nalezeny.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="pagination-wrap">
        {{ $vlans->links() }}
    </div>

    <form method="GET" action="{{ route('vlans.index') }}" style="margin-top:1em;">
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
