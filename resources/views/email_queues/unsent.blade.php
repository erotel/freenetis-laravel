@extends('layouts.app')
@section('title', 'Čekající e-maily')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">Čekající e-maily</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Čekající e-maily</h2></div>
<div class="m-subtitle">Celkem: {{ $emails->total() }} záznamů</div>

<div class="m-actions">
    <a class="m-btn" href="{{ route('email_queues.sent') }}">Odeslané e-maily</a>
    @if($canDelete && $emails->total() > 0)
    <form method="POST" action="{{ route('email_queues.destroy-unsent') }}" style="display:inline">
        @csrf @method('DELETE')
        <button class="m-btn m-btn-danger" type="submit"
                onclick="return confirm('Smazat všechny čekající e-maily ({{ $emails->total() }} ks)?')">
            Smazat vše čekající ({{ $emails->total() }} ks)
        </button>
    </form>
    @endif
</div>

<div class="m-card" style="margin-bottom:16px;padding:14px 1.25rem">
    <form method="GET" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">
        <div>
            <div class="m-form-label">Od</div>
            <input class="m-form-input" type="text" name="from" value="{{ $filterFrom }}" style="width:160px">
        </div>
        <div>
            <div class="m-form-label">Komu</div>
            <input class="m-form-input" type="text" name="to" value="{{ $filterTo }}" style="width:160px">
        </div>
        <div>
            <div class="m-form-label">Předmět</div>
            <input class="m-form-input" type="text" name="subject" value="{{ $filterSubj }}" style="width:180px">
        </div>
        <div style="display:flex;gap:6px;padding-bottom:1px">
            <button class="m-btn m-btn-primary" type="submit">Filtrovat</button>
            @if($filterFrom || $filterTo || $filterSubj)
            <a class="m-btn" href="{{ route('email_queues.unsent') }}">Zrušit filtr</a>
            @endif
        </div>
    </form>
</div>

<div style="margin-bottom:8px">{{ $emails->links() }}</div>

<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px">ID</th>
            <th>Od</th>
            <th>Komu</th>
            <th>Předmět</th>
            <th style="width:140px">Datum</th>
            <th style="width:100px">Akce</th>
        </tr>
    </thead>
    <tbody>
        @forelse($emails as $email)
        <tr>
            <td>{{ $email->id }}</td>
            <td style="font-size:12px">{{ $email->from }}</td>
            <td style="font-size:12px">{{ $email->to }}</td>
            <td style="font-size:12px">{{ Str::limit($email->subject, 60) }}</td>
            <td style="font-size:12px">{{ \Carbon\Carbon::parse($email->access_time)->format('d.m.Y H:i') }}</td>
            <td>
                <div style="display:flex;gap:6px">
                    @if($canSend)
                    <form method="POST" action="{{ route('email_queues.resend', $email->id) }}" style="display:inline">
                        @csrf
                        <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;font-size:12px;color:#27ae60"
                                onclick="return confirm('Odeslat e-mail #{{ $email->id }}?')">Odeslat</button>
                    </form>
                    @endif
                    @if($canDelete)
                    <form method="POST" action="{{ route('email_queues.destroy', $email->id) }}" style="display:inline">
                        @csrf @method('DELETE')
                        <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;font-size:12px;color:#c0392b"
                                onclick="return confirm('Smazat e-mail #{{ $email->id }}?')">Smazat</button>
                    </form>
                    @endif
                </div>
            </td>
        </tr>
        @empty
        <tr><td colspan="6" style="text-align:center;color:#aaa;padding:2rem">Žádné čekající e-maily.</td></tr>
        @endforelse
    </tbody>
</table>
</div>

<div style="margin-top:12px">{{ $emails->links() }}</div>
</div>
@endsection
