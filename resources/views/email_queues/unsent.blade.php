@extends('layouts.app')

@section('title', 'Čekající e-maily')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        Čekající e-maily
    </div>
@endsection

@section('content')
<h2>Čekající e-maily</h2>

@if(session('success'))
    <div class="message_ok"><p>{{ session('success') }}</p></div>
@endif
@if(session('error'))
    <div class="message_error"><p>{{ session('error') }}</p></div>
@endif

<p>
    <a href="{{ route('email_queues.sent') }}">Zobrazit odeslané e-maily</a>
    @if($canDelete && $emails->total() > 0)
        &nbsp;|&nbsp;
        <form method="POST" action="{{ route('email_queues.destroy-unsent') }}" style="display:inline">
            @csrf @method('DELETE')
            <button type="submit"
                    style="background:none;border:none;cursor:pointer;padding:0;color:#c00;text-decoration:underline;"
                    onclick="return confirm('Smazat všechny čekající e-maily ({{ $emails->total() }} ks)?')">
                Smazat vše čekající
            </button>
        </form>
    @endif
</p>

{{-- Filtr --}}
<form method="GET" style="margin-bottom:1em;">
    <label>Od: <input type="text" name="from" value="{{ $filterFrom }}" style="width:160px;"></label>
    &nbsp;
    <label>Komu: <input type="text" name="to" value="{{ $filterTo }}" style="width:160px;"></label>
    &nbsp;
    <label>Předmět: <input type="text" name="subject" value="{{ $filterSubj }}" style="width:180px;"></label>
    &nbsp;
    <button type="submit">Filtrovat</button>
    @if($filterFrom || $filterTo || $filterSubj)
        <a href="{{ route('email_queues.unsent') }}" style="margin-left:6px;">Zrušit filtr</a>
    @endif
</form>

<table class="extended" cellspacing="0">
    <thead>
        <tr>
            <th>ID</th>
            <th>Od</th>
            <th>Komu</th>
            <th>Předmět</th>
            <th>Datum</th>
            <th>Akce</th>
        </tr>
    </thead>
    <tbody>
        @forelse($emails as $email)
            <tr>
                <td>{{ $email->id }}</td>
                <td>{{ $email->from }}</td>
                <td>{{ $email->to }}</td>
                <td>{{ Str::limit($email->subject, 60) }}</td>
                <td>{{ \Carbon\Carbon::parse($email->access_time)->format('d.m.Y H:i') }}</td>
                <td>
                    @if($canSend)
                        <form method="POST" action="{{ route('email_queues.resend', $email->id) }}" style="display:inline">
                            @csrf
                            <button type="submit"
                                    style="background:none;border:none;cursor:pointer;padding:0;color:#060;text-decoration:underline;"
                                    onclick="return confirm('Odeslat e-mail #{{ $email->id }}?')">Odeslat</button>
                        </form>
                    @endif
                    @if($canDelete)
                        @if($canSend) | @endif
                        <form method="POST" action="{{ route('email_queues.destroy', $email->id) }}" style="display:inline">
                            @csrf @method('DELETE')
                            <button type="submit"
                                    style="background:none;border:none;cursor:pointer;padding:0;color:#c00;text-decoration:underline;"
                                    onclick="return confirm('Smazat e-mail #{{ $email->id }}?')">Smazat</button>
                        </form>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6" style="text-align:center">Žádné čekající e-maily.</td></tr>
        @endforelse
    </tbody>
</table>

<div style="margin-top:10px">{{ $emails->links() }}</div>
@endsection
