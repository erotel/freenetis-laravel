@extends('layouts.app')
@section('title', 'Zprávy')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
    <div id="breadcrumbs">Zprávy</div>
@endsection
@section('content')
    <h2>Uživatelské zprávy</h2>

    <div style="margin-bottom:1em;">
        <a href="{{ route('messages.create') }}">+ Přidat zprávu</a>
    </div>

    <table class="extended" cellspacing="0">
        <thead>
            <tr>
                <th>ID</th>
                <th>Název</th>
                <th>Zrušení členem</th>
                <th>Akce</th>
            </tr>
        </thead>
        <tbody>
            @forelse($userMessages as $msg)
                <tr>
                    <td>{{ $msg->id }}</td>
                    <td><a href="{{ route('messages.show', $msg->id) }}">{{ $msg->name }}</a></td>
                    <td>{{ ['Zakázáno', 'Člen', 'IP adresa'][$msg->self_cancel] }}</td>
                    <td>
                        <a href="{{ route('messages.show', $msg->id) }}">Detail</a>
                        | <a href="{{ route('messages.edit', $msg->id) }}">Upravit</a>
                        @if(in_array($msg->type, [5, 6, 25, 26]))
                            | <a href="{{ route('message-auto-settings.index', $msg->id) }}" title="Automatická aktivace">⚙</a>
                        @endif
                        | <form method="POST" action="{{ route('messages.destroy', $msg->id) }}" style="display:inline">
                            @csrf @method('DELETE')
                            <button type="submit" onclick="return confirm('Smazat zprávu?')">Smazat</button>
                          </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4">Žádné uživatelské zprávy.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2 style="margin-top:2em;">Systémové zprávy</h2>
    <p style="font-size:0.9em; color:#555;">Systémové zprávy jsou předdefinované a nelze je smazat. Lze upravit jejich obsah.</p>

    <table class="extended" cellspacing="0">
        <thead>
            <tr>
                <th>ID</th>
                <th>Typ</th>
                <th>Název</th>
                <th>Akce</th>
            </tr>
        </thead>
        <tbody>
            @foreach($systemMessages as $msg)
                <tr>
                    <td>{{ $msg->id }}</td>
                    <td><small style="color:#888;">{{ \App\Models\Message::typeLabel($msg->type) }}</small></td>
                    <td><a href="{{ route('messages.show', $msg->id) }}">{{ $msg->name }}</a></td>
                    <td>
                        <a href="{{ route('messages.show', $msg->id) }}">Detail</a>
                        | <a href="{{ route('messages.edit', $msg->id) }}">Upravit</a>
                        @if(in_array($msg->type, [5, 6, 25, 26]))
                            | <a href="{{ route('message-auto-settings.index', $msg->id) }}" title="Automatická aktivace">⚙</a>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endsection
