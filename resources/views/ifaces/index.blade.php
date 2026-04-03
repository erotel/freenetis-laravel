@extends('layouts.app')

@section('title', 'Seznam rozhraní')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('ifaces.index') }}">Rozhraní</a>
    </div>
@endsection

@section('content')
    <h2>Seznam rozhraní</h2>

    <form method="GET" action="{{ route('ifaces.index') }}" style="margin-bottom:1em;">
        @if(request('sort')) <input type="hidden" name="sort" value="{{ $sort }}"> @endif
        @if(request('dir'))  <input type="hidden" name="dir"  value="{{ $dir }}">  @endif
        <input type="text" name="search" value="{{ $search }}" placeholder="Název nebo MAC…">
        <input type="submit" value="Hledat">
        @if($search)
            <a href="{{ route('ifaces.index') }}">[×] Zrušit filtr</a>
        @endif
    </form>

    @php
        $nextDir = fn(string $col) => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
        $arrow   = fn(string $col) => $sort === $col ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
        $sortUrl = fn(string $col) => request()->fullUrlWithQuery(['sort' => $col, 'dir' => $nextDir($col), 'page' => 1]);
    @endphp

    <table class="extended" cellspacing="0">
        <thead>
            <tr>
                <th><a href="{{ $sortUrl('id') }}">ID{{ $arrow('id') }}</a></th>
                <th><a href="{{ $sortUrl('type') }}">Typ{{ $arrow('type') }}</a></th>
                <th><a href="{{ $sortUrl('name') }}">Název{{ $arrow('name') }}</a></th>
                <th>MAC</th>
                <th><a href="{{ $sortUrl('device_id') }}">Zařízení{{ $arrow('device_id') }}</a></th>
                <th>Člen</th>
                <th>Akce</th>
            </tr>
        </thead>
        <tbody>
            @forelse($ifaces as $iface)
                <tr>
                    <td>{{ $iface->id }}</td>
                    <td>{{ $iface->type_label }}</td>
                    <td>{{ $iface->name ?? '—' }}</td>
                    <td>{{ $iface->mac ?? '—' }}</td>
                    <td>
                        @if($iface->device)
                            <a href="{{ route('devices.show', $iface->device_id) }}">{{ $iface->device->name }}</a>
                        @else
                            —
                        @endif
                    </td>
                    <td>
                        @if($iface->device?->user?->member)
                            <a href="{{ route('members.show', $iface->device->user->member_id) }}">{{ $iface->device->user->member->name }}</a>
                        @else
                            —
                        @endif
                    </td>
                    <td>
                        <a href="{{ route('ifaces.show', $iface->id) }}" title="Detail">
                            <img src="{{ asset('media/images/icons/con_info.png') }}" alt="Detail">
                        </a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">Žádná rozhraní nebyla nalezena.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="pagination-wrap">
        {{ $ifaces->links() }}
    </div>

    <form method="GET" action="{{ route('ifaces.index') }}" style="margin-top:1em;">
        @if($search) <input type="hidden" name="search" value="{{ $search }}"> @endif
        @if(request('sort')) <input type="hidden" name="sort" value="{{ $sort }}"> @endif
        @if(request('dir'))  <input type="hidden" name="dir"  value="{{ $dir }}">  @endif
        Záznamů na stránku:
        <select name="record_per_page" onchange="this.form.submit()">
            @foreach([50, 100, 150, 200, 250, 300] as $n)
                <option value="{{ $n }}" @selected($perPage === $n)>{{ $n }}</option>
            @endforeach
        </select>
    </form>
@endsection
