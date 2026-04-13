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
                <th>Aktivní</th>
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
                    <td>{{ $row->enabled ? 'Ano' : 'Ne' }}</td>
                    @if($canEdit)
                        <td>
                            <a href="{{ route('public-port-forwards.edit', $row->id) }}" title="Upravit">
                                <img src="{{ asset('media/images/icons/gtk_edit.png') }}" alt="Upravit">
                            </a>
                            <form method="POST" action="{{ route('public-port-forwards.toggle', $row->id) }}" style="display:inline;">
                                @csrf
                                <button type="submit" class="icon-button"
                                        title="{{ $row->enabled ? 'Deaktivovat' : 'Aktivovat' }}">
                                    <img src="{{ asset('media/images/icons/' . ($row->enabled ? 'active.png' : 'inactive.png')) }}"
                                         alt="{{ $row->enabled ? 'Deaktivovat' : 'Aktivovat' }}">
                                </button>
                            </form>
                            <form method="POST" action="{{ route('public-port-forwards.destroy', $row->id) }}" style="display:inline;"
                                  onsubmit="return confirm('Opravdu smazat port forward #{{ $row->id }}?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="icon-button" title="Smazat">
                                    <img src="{{ asset('media/images/icons/delete.png') }}" alt="Smazat">
                                </button>
                            </form>
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
