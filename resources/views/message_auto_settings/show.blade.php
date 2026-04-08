@extends('layouts.app')
@section('title', 'Automatická aktivace: ' . $message->name)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('messages.index') }}">Zprávy</a> »
        <a href="{{ route('messages.show', $message->id) }}">{{ $message->name }}</a> »
        Automatická aktivace
    </div>
@endsection
@section('content')
    <h2>Nastavení automatické aktivace</h2>
    <p><strong>Zpráva:</strong> {{ $message->name }}</p>

    <div style="margin-bottom:1em;">
        <a href="{{ route('message-auto-settings.create', $message->id) }}">+ Přidat nové pravidlo</a>
    </div>

    @if($settings->isEmpty())
        <p>Žádná pravidla automatické aktivace.</p>
    @else
        <table class="extended" cellspacing="0">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Typ</th>
                    <th>Atribut</th>
                    <th>Přesměrování</th>
                    <th>E-mail</th>
                    <th>SMS</th>
                    <th>Nahlásil na</th>
                    <th>Akce</th>
                </tr>
            </thead>
            <tbody>
                @foreach($settings as $s)
                    <tr>
                        <td>{{ $s->id }}</td>
                        <td>{{ \App\Models\MessageAutoSetting::typeLabel($s->type) }}</td>
                        <td>{{ $s->attributeLabel() }}</td>
                        <td>{{ $s->redirection_enabled ? 'ano' : 'ne' }}</td>
                        <td>{{ $s->email_enabled ? 'ano' : 'ne' }}</td>
                        <td>{{ $s->sms_enabled ? 'ano' : 'ne' }}</td>
                        <td>{{ $s->send_activation_to_email ?? '—' }}</td>
                        <td>
                            <a href="{{ route('message-auto-settings.edit', $s->id) }}">Upravit</a>
                            | <form method="POST" action="{{ route('message-auto-settings.destroy', $s->id) }}" style="display:inline">
                                @csrf @method('DELETE')
                                <button type="submit" onclick="return confirm('Smazat pravidlo?')">Smazat</button>
                              </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div style="margin-top:1em;">
        <a href="{{ route('messages.show', $message->id) }}">← Zpět na zprávu</a>
    </div>
@endsection
