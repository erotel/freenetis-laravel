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
    <h2>Automatická aktivace: {{ $message->name }}</h2>

    @if(in_array($message->type, [5, 6, 25, 26]))
        <div style="margin-bottom:1em;">
            <a href="{{ route('message-auto-settings.create', $message->id) }}">+ Přidat pravidlo</a>
        </div>
    @endif

    @if($rules->isEmpty())
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
                    <th>Nahlásit na</th>
                    <th>Akce</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rules as $rule)
                    <tr>
                        <td>{{ $rule->id }}</td>
                        <td>{{ $typeLabels[$rule->type] ?? $rule->type }}</td>
                        <td>{{ $rule->attribute ?: '—' }}</td>
                        <td>{{ $rule->redirection_enabled ? 'ano' : 'ne' }}</td>
                        <td>{{ $rule->email_enabled ? 'ano' : 'ne' }}</td>
                        <td>{{ $rule->sms_enabled ? 'ano' : 'ne' }}</td>
                        <td>{{ $rule->send_activation_to_email ?: '—' }}</td>
                        <td>
                            <a href="{{ route('message-auto-settings.edit', [$message->id, $rule->id]) }}">✏ Upravit</a>
                            | <form method="POST" action="{{ route('message-auto-settings.destroy', [$message->id, $rule->id]) }}" style="display:inline">
                                @csrf @method('DELETE')
                                <button type="submit" onclick="return confirm('Smazat pravidlo?')">✕</button>
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
