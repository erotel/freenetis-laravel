@extends('layouts.app')
@section('title', 'Zpráva: ' . $message->name)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('messages.index') }}">Zprávy</a> &raquo; {{ $message->name }}
    </div>
@endsection
@section('content')
    <h2>Zpráva: {{ $message->name }}</h2>

    <p>
        <a href="{{ route('messages.edit', $message->id) }}">Upravit</a>
        @if(!$message->isSystem())
            | <form method="POST" action="{{ route('messages.destroy', $message->id) }}" style="display:inline">
                @csrf @method('DELETE')
                <button type="submit" onclick="return confirm('Smazat zprávu?')">Smazat</button>
              </form>
        @endif
    </p>

    <table class="extended" cellspacing="0">
        <tr><th>ID</th><td>{{ $message->id }}</td></tr>
        <tr><th>Typ</th><td>{{ \App\Models\Message::typeLabel($message->type) }}</td></tr>
        <tr><th>Název</th><td>{{ $message->name }}</td></tr>
        <tr><th>Zrušení členem</th><td>{{ ['Zakázáno', 'Člen může zrušit', 'IP může zrušit'][$message->self_cancel ?? 0] }}</td></tr>
        <tr><th>Ignorovat whitelist</th><td>{{ $message->ignore_whitelist ? 'Ano' : 'Ne' }}</td></tr>
        @if($message->text)
            <tr><th style="vertical-align:top">Text přesměrování</th><td>{!! $message->text !!}</td></tr>
        @endif
        @if($message->email_text)
            <tr><th style="vertical-align:top">Text emailu</th><td>{!! $message->email_text !!}</td></tr>
        @endif
        @if($message->sms_text)
            <tr><th style="vertical-align:top">Text SMS</th><td>{{ $message->sms_text }}</td></tr>
        @endif
    </table>

    <h3 style="margin-top:1.5em;">Aktivní IP adresy</h3>
    @if($activeIps->isEmpty())
        <p>Zpráva není aktivní pro žádnou IP adresu.</p>
    @else
        <table class="extended" cellspacing="0">
            <thead>
                <tr>
                    <th>IP adresa</th>
                    <th>Aktivoval</th>
                    <th>Komentář</th>
                    <th>Datum</th>
                    <th>Akce</th>
                </tr>
            </thead>
            <tbody>
                @foreach($activeIps as $ip)
                    <tr>
                        <td>{{ $ip->ip_address }}</td>
                        <td>{{ $ip->activated_by ?? '—' }}</td>
                        <td>{{ $ip->comment ?? '—' }}</td>
                        <td>{{ $ip->datetime }}</td>
                        <td>
                            <form method="POST" action="{{ route('messages.deactivate', [$message->id, $ip->ip_address_id]) }}" style="display:inline">
                                @csrf @method('DELETE')
                                <button type="submit">Deaktivovat</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
