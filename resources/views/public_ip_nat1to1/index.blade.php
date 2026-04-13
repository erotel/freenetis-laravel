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
                <th>Poslední změna</th>
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
                    @php
                        $mod  = $row->modified ? \Carbon\Carbon::parse($row->modified) : null;
                        $useModified = $mod && $mod->year >= 2000;
                        $cre = $row->created ? \Carbon\Carbon::parse($row->created) : null;
                        $useCreated  = $cre && $cre->year >= 2000;
                        $changeDate  = $useModified ? $mod->format('d.m.Y H:i') : ($useCreated ? $cre->format('d.m.Y H:i') : '—');
                        $changeName  = $useModified ? ($row->modified_by_name ?: '—') : ($useCreated ? ($row->created_by_name ?: '—') : null);
                    @endphp
                    <td>{{ $changeDate }}{{ $changeName !== null ? ' (' . $changeName . ')' : '' }}</td>
                    @if($canEdit)
                        <td>
                            <a href="{{ route('public-ip-nat.edit', $row->id) }}">Upravit</a>
                            @if($row->private_ip)
                                &nbsp;|&nbsp;
                                <a href="{{ route('public-ip-nat.clear', $row->id) }}"
                                   onclick="return confirm('Opravdu vymazat mapování pro {{ $row->public_ip }}?');">Vymazat mapování</a>
                            @endif
                        </td>
                    @endif
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $canEdit ? 6 : 5 }}">Žádné záznamy.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection
