@extends('layouts.app')
@section('title', 'Zpráva: ' . $message->name)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('messages.index') }}">Zprávy</a> &raquo; {{ $message->name }}
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Zpráva: {{ $message->name }}</h2></div>

<div class="m-actions">
    <a class="m-btn" href="{{ route('messages.edit', $message->id) }}">Upravit</a>
    @if(in_array($message->type, [5, 6, 25, 26]))
    <a class="m-btn" href="{{ route('message-auto-settings.index', $message->id) }}">⚙ Automatická aktivace</a>
    @endif
    @if(!$message->isSystem())
    <form method="POST" action="{{ route('messages.destroy', $message->id) }}" style="display:inline">
        @csrf @method('DELETE')
        <button class="m-btn m-btn-danger" type="submit" onclick="return confirm('Smazat zprávu?')">Smazat</button>
    </form>
    @endif
</div>

<div class="m-card" style="max-width:560px;margin-bottom:16px">
    <div class="m-card-title">Informace o zprávě</div>
    <div class="m-field"><span class="m-field-label">ID</span><span class="m-field-value">{{ $message->id }}</span></div>
    <div class="m-field"><span class="m-field-label">Typ</span><span class="m-field-value">{{ \App\Models\Message::typeLabel($message->type) }}</span></div>
    <div class="m-field"><span class="m-field-label">Název</span><span class="m-field-value">{{ $message->name }}</span></div>
    <div class="m-field"><span class="m-field-label">Zrušení členem</span><span class="m-field-value">{{ ['Zakázáno', 'Člen může zrušit', 'IP může zrušit'][$message->self_cancel ?? 0] }}</span></div>
    <div class="m-field"><span class="m-field-label">Ignorovat whitelist</span><span class="m-field-value">{{ $message->ignore_whitelist ? 'Ano' : 'Ne' }}</span></div>
    @if($message->text)
    <div class="m-field" style="align-items:flex-start;flex-direction:column;gap:4px">
        <span class="m-field-label">Text přesměrování</span>
        <div style="font-size:13px;background:#f7f7f5;border-radius:6px;padding:8px 10px;width:100%;white-space:pre-wrap">{{ $message->text }}</div>
    </div>
    @endif
    @if($message->email_text)
    <div class="m-field" style="align-items:flex-start;flex-direction:column;gap:4px">
        <span class="m-field-label">Text emailu</span>
        <div style="font-size:13px;background:#f7f7f5;border-radius:6px;padding:8px 10px;width:100%;white-space:pre-wrap">{{ $message->email_text }}</div>
    </div>
    @endif
    @if($message->sms_text)
    <div class="m-field" style="align-items:flex-start;flex-direction:column;gap:4px">
        <span class="m-field-label">Text SMS</span>
        <div style="font-size:13px;background:#f7f7f5;border-radius:6px;padding:8px 10px;width:100%">{{ $message->sms_text }}</div>
    </div>
    @endif
</div>

@if($activeIps->isNotEmpty())
<div class="m-section">Aktivní IP adresy</div>
<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th>IP adresa</th>
            <th>Aktivoval</th>
            <th>Komentář</th>
            <th style="width:140px">Datum</th>
            <th style="width:100px">Akce</th>
        </tr>
    </thead>
    <tbody>
        @foreach($activeIps as $ip)
        <tr>
            <td style="font-family:monospace">{{ $ip->ip_address }}</td>
            <td>{{ $ip->activated_by ?? '—' }}</td>
            <td>{{ $ip->comment ?? '—' }}</td>
            <td style="font-size:12px">{{ $ip->datetime }}</td>
            <td>
                <form method="POST" action="{{ route('messages.deactivate', [$message->id, $ip->ip_address_id]) }}" style="display:inline">
                    @csrf @method('DELETE')
                    <button type="submit" class="m-btn m-btn-danger" style="font-size:12px;padding:3px 8px">Deaktivovat</button>
                </form>
            </td>
        </tr>
        @endforeach
    </tbody>
</table>
</div>
@else
<div class="m-alert m-alert-info">Zpráva není aktivní pro žádnou IP adresu.</div>
@endif

</div>
@endsection
