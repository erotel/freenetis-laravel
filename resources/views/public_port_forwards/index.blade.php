@extends('layouts.app')

@section('title', 'Veřejné porty')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('public-port-forwards.index') }}">Veřejné porty</a>
    </div>
@endsection

@section('content')
    <h2>Veřejné porty (port forwarding)</h2>

    @if(session('success'))
        <div class="message_success">{{ session('success') }}</div>
    @endif

    @if($canEdit)
        <p>
            <a href="{{ route('public-port-forwards.create') }}">
                <img src="{{ asset('media/images/icons/ico_add.gif') }}" alt="Přidat">
                Přidat port forward
            </a>
        </p>
    @endif

    <table class="extended" cellspacing="0">
        <thead>
            <tr>
                <th>ID</th>
                <th>Protokol</th>
                <th>Veřejná IP</th>
                <th>Veřejné porty</th>
                <th>Privátní IP</th>
                <th>Privátní porty</th>
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
                    <td>{{ strtoupper($row->protocol) }}</td>
                    <td>{{ $row->public_ip }}</td>
                    <td>
                        @if($row->public_port_from == $row->public_port_to)
                            {{ $row->public_port_from }}
                        @else
                            {{ $row->public_port_from }}–{{ $row->public_port_to }}
                        @endif
                    </td>
                    <td>{{ $row->private_ip }}</td>
                    <td>
                        @if($row->private_port_from == $row->private_port_to)
                            {{ $row->private_port_from }}
                        @else
                            {{ $row->private_port_from }}–{{ $row->private_port_to }}
                        @endif
                    </td>
                    <td>{{ $row->owner_member_name ?: '—' }}</td>
                    @php
                        $mod = $row->modified ? \Carbon\Carbon::parse($row->modified) : null;
                        $cre = $row->created  ? \Carbon\Carbon::parse($row->created)  : null;
                        $useModified = $mod && $mod->year >= 2000;
                        $useCreated  = $cre && $cre->year >= 2000;
                        $changeDate  = $useModified ? $mod->format('d.m.Y H:i') : ($useCreated ? $cre->format('d.m.Y H:i') : '—');
                        $changeName  = $useModified ? ($row->modified_by_name ?: '—') : ($useCreated ? ($row->created_by_name ?: '—') : null);
                    @endphp
                    <td>{{ $changeDate }}{{ $changeName !== null ? ' (' . $changeName . ')' : '' }}</td>
                    @if($canEdit)
                        <td>
                            <a href="{{ route('public-port-forwards.edit', $row->id) }}">Upravit</a>
                            &nbsp;|&nbsp;
                            <a href="{{ route('public-port-forwards.destroy', $row->id) }}"
                               onclick="return confirm('Opravdu smazat port forward #{{ $row->id }}?');">Smazat</a>
                        </td>
                    @endif
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $canEdit ? 9 : 8 }}">Žádné záznamy.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection
