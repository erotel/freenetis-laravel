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
<div class="m-title-row"><h2>Nastavení automatické aktivace: {{ $message->name }}</h2></div>

<div class="m-actions">
    <a class="m-btn" href="{{ route('messages.show', $message->id) }}">&larr; Zpět na zprávu</a>
    <a class="m-btn m-btn-success" href="{{ route('message-auto-settings.create', $message->id) }}">+ Přidat nové pravidlo</a>
</div>

@if($settings->isEmpty())
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
            <th>Nahlásil na</th>
            <th style="width:80px">Akce</th>
        </tr>
    </thead>
    <tbody>
        @foreach($settings as $s)
        <tr>
            <td>{{ $s->id }}</td>
            <td style="font-size:14px">{{ \App\Models\MessageAutoSetting::typeLabel($s->type) }}</td>
            <td style="font-family:monospace;font-size:14px">{{ $s->attributeLabel() }}</td>
            <td>@if($s->redirection_enabled)<span class="m-tag m-tag-green">Ano</span>@else —@endif</td>
            <td>@if($s->email_enabled)<span class="m-tag m-tag-green">Ano</span>@else —@endif</td>
            <td>@if($s->sms_enabled)<span class="m-tag m-tag-green">Ano</span>@else —@endif</td>
            <td style="font-size:14px">{{ $s->send_activation_to_email ?? '—' }}</td>
            <td>
                <div style="display:flex;gap:6px">
                    <a class="m-link-sm" href="{{ route('message-auto-settings.edit', $s->id) }}">Upravit</a>
                    <form method="POST" action="{{ route('message-auto-settings.destroy', $s->id) }}" style="display:inline">
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
