@extends('layouts.app')
@section('title', 'E-mail #' . $email->id)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('email_queues.sent') }}">E-maily</a> / #{{ $email->id }}
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>E-mail #{{ $email->id }}</h2></div>

<div class="m-actions">
    <a class="m-btn" href="{{ url()->previous() }}">← Zpět</a>
    @if($canSend && $email->state !== \App\Models\EmailQueue::STATE_SENT)
    <form method="POST" action="{{ route('email_queues.resend', $email->id) }}" style="display:inline">
        @csrf
        <button class="m-btn m-btn-primary" type="submit"
                onclick="return confirm('Odeslat e-mail #{{ $email->id }}?')">Odeslat</button>
    </form>
    @endif
    @if($canDelete && $email->state === \App\Models\EmailQueue::STATE_NEW)
    <form method="POST" action="{{ route('email_queues.destroy', $email->id) }}" style="display:inline">
        @csrf @method('DELETE')
        <button class="m-btn m-btn-danger" type="submit"
                onclick="return confirm('Smazat e-mail #{{ $email->id }}?')">Smazat</button>
    </form>
    @endif
</div>

<div class="m-card" style="margin-bottom:16px">
    <table class="m-table" style="margin-bottom:0">
        <tbody>
            <tr><th style="width:140px">Od</th><td>{{ $email->from }}</td></tr>
            <tr><th>Komu</th><td>{{ $email->to }}</td></tr>
            <tr><th>Předmět</th><td>{{ $email->subject }}</td></tr>
            <tr><th>Stav</th><td>
                @switch($email->state)
                    @case(\App\Models\EmailQueue::STATE_NEW)    Čeká @break
                    @case(\App\Models\EmailQueue::STATE_SENT)   Odesláno @break
                    @case(\App\Models\EmailQueue::STATE_FAILED) Selhalo @break
                    @default {{ $email->state }}
                @endswitch
            </td></tr>
            <tr><th>Datum</th><td>{{ $email->access_time ? \Carbon\Carbon::parse($email->access_time)->format('d.m.Y H:i:s') : '—' }}</td></tr>
        </tbody>
    </table>
</div>

@if($email->attachments->isNotEmpty())
<div class="m-card" style="margin-bottom:16px">
    <div class="m-card-title">Přílohy ({{ $email->attachments->count() }})</div>
    <ul style="margin:0;padding-left:18px">
        @foreach($email->attachments as $att)
        <li>
            <a href="{{ route('email_queues.attachment', [$email->id, $att->id]) }}">{{ $att->name ?: basename($att->path) }}</a>
            <span style="color:#888;font-size:14px">({{ $att->mime ?: 'application/octet-stream' }})</span>
        </li>
        @endforeach
    </ul>
</div>
@endif

<div class="m-card">
    <div class="m-card-title">Obsah</div>
    {{-- Sandbox bez allow-same-origin: obsah běží v unique-origin, takže žádné JS,
         žádný přístup k cookies / DOM rodiče. Auto-resize tím pádem nejde, fixovaná
         výška s vnitřním scrollem je dost čitelná. --}}
    <iframe sandbox srcdoc="{{ $bodyHtml ?? $email->body }}"
            style="width:100%;height:75vh;border:1px solid #d4cfc6;border-radius:4px;background:#fff"></iframe>
</div>

</div>
@endsection
