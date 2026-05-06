@extends('layouts.app')
@section('title', 'Zprávy')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">Zprávy</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Uživatelské zprávy</h2></div>

<div class="m-actions">
    <a class="m-btn m-btn-success" href="{{ route('messages.create') }}">+ Přidat zprávu</a>
</div>

<div class="m-card" style="padding:0;overflow-x:auto;margin-bottom:24px">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px">ID</th>
            <th>Název</th>
            <th style="width:150px">Zrušení členem</th>
            <th style="width:130px">Akce</th>
        </tr>
    </thead>
    <tbody>
        @forelse($userMessages as $msg)
        <tr>
            <td>{{ $msg->id }}</td>
            <td><a class="m-link" href="{{ route('messages.show', $msg->id) }}">{{ $msg->name }}</a></td>
            <td style="font-size:14px">{{ ['Zakázáno', 'Člen', 'IP adresa'][$msg->self_cancel] }}</td>
            <td>
                <div style="display:flex;gap:6px">
                    <a class="m-link-sm" href="{{ route('messages.show', $msg->id) }}">Detail</a>
                    <a class="m-link-sm" href="{{ route('messages.edit', $msg->id) }}">Upravit</a>
                    @if(in_array($msg->type, [5, 6, 25, 26]))
                    <a class="m-link-sm" href="{{ route('message-auto-settings.index', $msg->id) }}" title="Automatická aktivace">⚙</a>
                    @endif
                    <form method="POST" action="{{ route('messages.destroy', $msg->id) }}" style="display:inline">
                        @csrf @method('DELETE')
                        <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;font-size:14px;color:#c0392b"
                                onclick="return confirm('Smazat zprávu?')">Smazat</button>
                    </form>
                </div>
            </td>
        </tr>
        @empty
        <tr><td colspan="4" style="text-align:center;color:#aaa;padding:2rem">Žádné uživatelské zprávy.</td></tr>
        @endforelse
    </tbody>
</table>
</div>

<div class="m-title-row"><h2>Systémové zprávy</h2></div>
<div class="m-subtitle">Systémové zprávy jsou předdefinované a nelze je smazat. Lze upravit jejich obsah.</div>

<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px">ID</th>
            <th style="width:130px">Typ</th>
            <th>Název</th>
            <th style="width:100px">Akce</th>
        </tr>
    </thead>
    <tbody>
        @foreach($systemMessages as $msg)
        <tr>
            <td>{{ $msg->id }}</td>
            <td><span class="m-tag m-tag-gray" style="font-size:13px">{{ \App\Models\Message::typeLabel($msg->type) }}</span></td>
            <td><a class="m-link" href="{{ route('messages.show', $msg->id) }}">{{ $msg->name }}</a></td>
            <td>
                <div style="display:flex;gap:6px">
                    <a class="m-link-sm" href="{{ route('messages.show', $msg->id) }}">Detail</a>
                    <a class="m-link-sm" href="{{ route('messages.edit', $msg->id) }}">Upravit</a>
                    @if(in_array($msg->type, [5, 6, 25, 26]))
                    <a class="m-link-sm" href="{{ route('message-auto-settings.index', $msg->id) }}">⚙</a>
                    @endif
                </div>
            </td>
        </tr>
        @endforeach
    </tbody>
</table>
</div>
</div>
@endsection
