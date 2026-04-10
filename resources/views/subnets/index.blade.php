@extends('layouts.app')

@section('title', 'Subnety')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('subnets.index') }}">Subnety</a>
    </div>
@endsection

@section('content')
    <h2>Seznam subnetů</h2>

    <form method="GET" action="{{ route('subnets.index') }}" style="margin-bottom:1em;">
        <input type="text" name="search" value="{{ $search }}" placeholder="Hledat podle názvu nebo IP...">
        <button type="submit">Hledat</button>
        @if($search)
            <a href="{{ route('subnets.index') }}">Zrušit filtr</a>
        @endif
    </form>

    @if($canNew)
        <p>
            <a href="{{ route('subnets.create') }}">
                <img src="{{ asset('media/images/icons/ico_add.gif') }}" alt="Přidat">
                Přidat subnet
            </a>
        </p>
    @endif

    @php
        $nextDir = fn(string $col) => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
        $arrow   = fn(string $col) => $sort === $col ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
        $sortUrl = fn(string $col) => request()->fullUrlWithQuery(['sort' => $col, 'dir' => $nextDir($col), 'page' => 1]);
    @endphp

    {{ $subnets->links() }}
    <table class="extended" cellspacing="0">
        <thead>
            <tr>
                <th><a href="{{ $sortUrl('id') }}">ID{{ $arrow('id') }}</a></th>
                <th><a href="{{ $sortUrl('name') }}">Název{{ $arrow('name') }}</a></th>
                <th><a href="{{ $sortUrl('network_address') }}">Síť/Maska{{ $arrow('network_address') }}</a></th>
                <th>Počet IP</th>
                @if($showDhcp) <th>DHCP</th> @endif
                @if($showDns)  <th>DNS</th>  @endif
                @if($showQos)  <th>QoS</th>  @endif
                <th>Akce</th>
            </tr>
        </thead>
        <tbody>
            @forelse($subnets as $subnet)
                <tr>
                    <td>{{ $subnet->id }}</td>
                    <td><a href="{{ route('subnets.show', $subnet->id) }}">{{ $subnet->name ?: '—' }}</a></td>
                    <td>{{ $subnet->network_address }}/{{ $subnet->netmask }}</td>
                    <td>{{ $subnet->ip_addresses_count }}</td>
                    @if($showDhcp) <td>{{ $subnet->dhcp ? '✓' : '—' }}</td> @endif
                    @if($showDns)  <td>{{ $subnet->dns  ? '✓' : '—' }}</td> @endif
                    @if($showQos)  <td>{{ $subnet->qos  ? '✓' : '—' }}</td> @endif
                    <td>
                        <a href="{{ route('subnets.show', $subnet->id) }}" title="Detail">
                            <img src="{{ asset('media/images/icons/con_info.png') }}" alt="Detail">
                        </a>
                        @if($canEdit)
                            <a href="{{ route('subnets.edit', $subnet->id) }}" title="Upravit">
                                <img src="{{ asset('media/images/icons/gtk_edit.png') }}" alt="Upravit">
                            </a>
                        @endif
                        @if($canDelete)
                            <form method="POST" action="{{ route('subnets.destroy', $subnet->id) }}" style="display:inline;"
                                  onsubmit="return confirm('Opravdu smazat subnet {{ addslashes($subnet->network_address) }}?');">
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
                    <td colspan="{{ 4 + ($showDhcp ? 1 : 0) + ($showDns ? 1 : 0) + ($showQos ? 1 : 0) + 1 }}">
                        Žádné subnety nebyly nalezeny.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="pagination-wrap">
        {{ $subnets->links() }}
    </div>

    <form method="GET" action="{{ route('subnets.index') }}" style="margin-top:1em;">
        @if(request('sort'))   <input type="hidden" name="sort"   value="{{ $sort }}"> @endif
        @if(request('dir'))    <input type="hidden" name="dir"    value="{{ $dir }}">  @endif
        @if(request('search')) <input type="hidden" name="search" value="{{ $search }}"> @endif
        Záznamů na stránku:
        <select name="record_per_page" onchange="this.form.submit()">
            @foreach([50, 100, 150, 200, 250, 300, 350, 400, 450, 500] as $n)
                <option value="{{ $n }}" @selected($perPage === $n)>{{ $n }}</option>
            @endforeach
        </select>
    </form>
@endsection
