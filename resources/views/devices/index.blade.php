@extends('layouts.app')

@section('title', 'Zařízení')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('devices.index') }}">Zařízení</a>
    </div>
@endsection

@section('content')
    <h2>Seznam zařízení</h2>

    <form method="GET" action="{{ route('devices.index') }}" style="margin-bottom:1em;">
        <input type="text" name="search" value="{{ $search }}" placeholder="Hledat podle názvu...">
        <button type="submit">Hledat</button>
        @if($search)
            <a href="{{ route('devices.index') }}">Zrušit filtr</a>
        @endif
    </form>

    @if($canNew)
        <p>
            <a href="{{ route('devices.create') }}">
                <img src="{{ asset('media/images/icons/ico_add.gif') }}" alt="Přidat">
                Přidat zařízení
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
                <th><a href="{{ $sortUrl('type') }}">Typ{{ $arrow('type') }}</a></th>
                <th><a href="{{ $sortUrl('user_id') }}">Uživatel{{ $arrow('user_id') }}</a></th>
                <th><a href="{{ $sortUrl('access_time') }}">Poslední přístup{{ $arrow('access_time') }}</a></th>
                <th>Akce</th>
            </tr>
        </thead>
        <tbody>
            @forelse($devices as $device)
                <tr>
                    <td>{{ $device->id }}</td>
                    <td><a href="{{ route('devices.show', $device->id) }}">{{ $device->name }}</a></td>
                    <td>{{ $device->enumType?->value ?? '—' }}</td>
                    <td>
                        @if($device->user)
                            <a href="{{ route('users.show', $device->user_id) }}">{{ $device->user->login }}</a>
                        @else
                            —
                        @endif
                    </td>
                    <td>{{ $device->access_time ?? '—' }}</td>
                    <td>
                        <a href="{{ route('devices.show', $device->id) }}" title="Detail">
                            <img src="{{ asset('media/images/icons/con_info.png') }}" alt="Detail">
                        </a>
                        @if($canEdit)
                            <a href="{{ route('devices.edit', $device->id) }}" title="Upravit">
                                <img src="{{ asset('media/images/icons/gtk_edit.png') }}" alt="Upravit">
                            </a>
                        @endif
                        @if($canDelete)
                            <form method="POST" action="{{ route('devices.destroy', $device->id) }}" style="display:inline;"
                                  onsubmit="return confirm('Opravdu smazat zařízení {{ addslashes($device->name) }}?');">
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
                    <td colspan="6">Žádná zařízení nebyla nalezena.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="pagination-wrap">
        {{ $devices->links() }}
    </div>

    <form method="GET" action="{{ route('devices.index') }}" style="margin-top:1em;">
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
