@extends('layouts.app')

@section('title', 'IP adresy')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('ip_addresses.index') }}">IP adresy</a>
    </div>
@endsection

@section('content')
    <h2>Seznam IP adres</h2>

    @if(session('success'))
        <p class="success">{{ session('success') }}</p>
    @endif
    @if(session('error'))
        <p class="error">{{ session('error') }}</p>
    @endif

    <form method="GET" action="{{ route('ip_addresses.index') }}" style="margin-bottom:1em;">
        <input type="text" name="search" value="{{ $search }}" placeholder="Hledat podle IP adresy...">
        <button type="submit">Hledat</button>
        @if($search)
            <a href="{{ route('ip_addresses.index') }}">Zrušit filtr</a>
        @endif
    </form>

    @if($canNew)
        <p>
            <a href="{{ route('ip_addresses.create') }}">
                <img src="{{ asset('media/images/icons/ico_add.gif') }}" alt="Přidat">
                Přidat IP adresu
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
                <th><a href="{{ $sortUrl('ip_address') }}">IP adresa{{ $arrow('ip_address') }}</a></th>
                <th><a href="{{ $sortUrl('subnet_id') }}">Subnet{{ $arrow('subnet_id') }}</a></th>
                <th><a href="{{ $sortUrl('member_id') }}">Člen{{ $arrow('member_id') }}</a></th>
                <th>Zařízení</th>
                <th>DHCP</th>
                <th>Gateway</th>
                <th>Akce</th>
            </tr>
        </thead>
        <tbody>
            @forelse($ipAddresses as $ip)
                <tr>
                    <td>{{ $ip->id }}</td>
                    <td><a href="{{ route('ip_addresses.show', $ip->id) }}">{{ $ip->ip_address }}</a></td>
                    <td>{{ $ip->subnet?->network_address ?? '—' }}</td>
                    <td>
                        @if($ip->member)
                            <a href="{{ route('members.show', $ip->member_id) }}">{{ $ip->member->name }}</a>
                        @else
                            —
                        @endif
                    </td>
                    <td>{{ $ip->iface?->device?->name ?? '—' }}</td>
                    <td>{{ $ip->dhcp ? '✓' : '—' }}</td>
                    <td>{{ $ip->gateway ? '✓' : '—' }}</td>
                    <td>
                        <a href="{{ route('ip_addresses.show', $ip->id) }}" title="Detail">
                            <img src="{{ asset('media/images/icons/con_info.png') }}" alt="Detail">
                        </a>
                        @if($canEdit)
                            <a href="{{ route('ip_addresses.edit', $ip->id) }}" title="Upravit">
                                <img src="{{ asset('media/images/icons/gtk_edit.png') }}" alt="Upravit">
                            </a>
                        @endif
                        @if($canDelete)
                            <form method="POST" action="{{ route('ip_addresses.destroy', $ip->id) }}" style="display:inline;"
                                  onsubmit="return confirm('Opravdu smazat IP adresu {{ addslashes($ip->ip_address) }}?');">
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
                    <td colspan="8">Žádné IP adresy nebyly nalezeny.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="pagination-wrap">
        {{ $ipAddresses->links() }}
    </div>

    <form method="GET" action="{{ route('ip_addresses.index') }}" style="margin-top:1em;">
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
