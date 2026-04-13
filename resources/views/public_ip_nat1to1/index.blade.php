@extends('layouts.app')

@section('title', 'Veřejné IP (1:1 NAT)')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('public-ip-nat.index') }}">Veřejné IP (1:1 NAT)</a>
    </div>
@endsection

@section('content')
    <h2>Veřejné IP (1:1 NAT)</h2>

    @if(session('success'))
        <div class="message_success">{{ session('success') }}</div>
    @endif

    <form method="GET" action="{{ route('public-ip-nat.index') }}" style="margin-bottom:1em;">
        <select name="enabled" onchange="this.form.submit()">
            <option value="all" @selected($enabled === 'all')>— vše —</option>
            <option value="1"   @selected($enabled === '1')>Aktivní</option>
            <option value="0"   @selected($enabled === '0')>Neaktivní</option>
        </select>
        <input type="text" name="q" value="{{ $q }}" placeholder="Hledat (IP, člen)...">
        <button type="submit">Hledat</button>
        @if($q || $enabled !== 'all')
            <a href="{{ route('public-ip-nat.index') }}">Zrušit filtr</a>
        @endif
    </form>

    <table class="extended" cellspacing="0">
        <thead>
            <tr>
                <th>ID</th>
                <th>Veřejná IP</th>
                <th>Privátní IP</th>
                <th>Člen</th>
                <th>Aktivní</th>
                <th>Upraveno</th>
                <th>Upravil</th>
                @if($canEdit)
                    <th>Akce</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    <td>{{ $row->id }}</td>
                    <td>{{ $row->public_ip }}</td>
                    <td>{{ $row->private_ip ?: '—' }}</td>
                    <td>{{ $row->owner_member_name ?: '—' }}</td>
                    <td>{{ $row->enabled ? 'Ano' : 'Ne' }}</td>
                    @php $mod = $row->modified ? \Carbon\Carbon::parse($row->modified) : null; @endphp
                    <td>{{ ($mod && $mod->year >= 2000) ? $mod->format('d.m.Y H:i') : '—' }}</td>
                    <td>{{ $row->modified_by_name ?: '—' }}</td>
                    @if($canEdit)
                        <td>
                            {{-- Edit --}}
                            <a href="{{ route('public-ip-nat.edit', $row->id) }}" title="Upravit">
                                <img src="{{ asset('media/images/icons/gtk_edit.png') }}" alt="Upravit">
                            </a>

                            {{-- Toggle enabled --}}
                            <form method="POST" action="{{ route('public-ip-nat.toggle', $row->id) }}" style="display:inline;">
                                @csrf
                                <button type="submit" class="icon-button"
                                        title="{{ $row->enabled ? 'Deaktivovat' : 'Aktivovat' }}">
                                    <img src="{{ asset('media/images/icons/' . ($row->enabled ? 'active.png' : 'inactive.png')) }}"
                                         alt="{{ $row->enabled ? 'Deaktivovat' : 'Aktivovat' }}">
                                </button>
                            </form>

                            {{-- Clear private IP mapping (keep row) --}}
                            @if($row->private_ip)
                                <form method="POST" action="{{ route('public-ip-nat.clear', $row->id) }}" style="display:inline;"
                                      onsubmit="return confirm('Vymazat mapování pro {{ $row->public_ip }}? Řádek zůstane, pouze se odstraní privátní IP.');">
                                    @csrf
                                    <button type="submit" class="icon-button" title="Vymazat mapování (ponechat řádek)">
                                        <img src="{{ asset('media/images/icons/ico_del.gif') }}" alt="Vymazat mapování">
                                    </button>
                                </form>
                            @endif

                        </td>
                    @endif
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $canEdit ? 8 : 7 }}">Žádné záznamy.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection
