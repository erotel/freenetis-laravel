@extends('layouts.app')
@section('title', 'Automatická aktivace: ' . $message->name)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('messages.index') }}">Zprávy</a> &raquo;
    <a href="{{ route('messages.show', $message->id) }}">{{ $message->name }}</a> &raquo;
    Automatická aktivace
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Automatická aktivace: {{ $message->name }}</h2></div>

<div class="m-actions">
    <a class="m-btn" href="{{ route('messages.show', $message->id) }}">&larr; Zpět na zprávu</a>
    @if(in_array($message->type, [5, 6, 25, 26]))
    <a class="m-btn m-btn-success" href="{{ route('message-auto-settings.create', $message->id) }}">+ Přidat pravidlo</a>
    @endif
</div>

@if($rules->isEmpty())
<div class="m-alert m-alert-info">Žádná pravidla automatické aktivace.</div>
@else
<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px">ID</th>
            <th style="width:130px">Typ</th>
            <th>Atribut</th>
            <th style="width:90px">Přesměrování</th>
            <th style="width:60px">E-mail</th>
            <th style="width:50px">SMS</th>
            <th>Nahlásit na</th>
            <th style="width:80px">Akce</th>
        </tr>
    </thead>
    <tbody>
        @foreach($rules as $rule)
        <tr>
            <td>{{ $rule->id }}</td>
            <td style="font-size:14px">{{ $typeLabels[$rule->type] ?? $rule->type }}</td>
            <td style="font-family:monospace;font-size:14px">{{ $rule->attribute ?: '—' }}</td>
            <td>@if($rule->redirection_enabled)<span class="m-tag m-tag-green">Ano</span>@else —@endif</td>
            <td>@if($rule->email_enabled)<span class="m-tag m-tag-green">Ano</span>@else —@endif</td>
            <td>@if($rule->sms_enabled)<span class="m-tag m-tag-green">Ano</span>@else —@endif</td>
            <td style="font-size:14px">{{ $rule->send_activation_to_email ?: '—' }}</td>
            <td>
                <div style="display:flex;gap:6px">
                    <a class="m-link-sm" href="{{ route('message-auto-settings.edit', [$message->id, $rule->id]) }}">Upravit</a>
                    <form method="POST" action="{{ route('message-auto-settings.destroy', [$message->id, $rule->id]) }}" style="display:inline">
                        @csrf @method('DELETE')
                        <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;font-size:14px;color:#c0392b"
                                onclick="return confirm('Smazat pravidlo?')">Smazat</button>
                    </form>
                </div>
            </td>
        </tr>
        @endforeach
    </tbody>
</table>
</div>
@endif
</div>
@endsection
