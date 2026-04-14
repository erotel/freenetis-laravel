@extends('layouts.app')

@section('title', 'Odeslané e-maily')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        Odeslané e-maily
    </div>
@endsection

@section('content')
<h2>Odeslané e-maily</h2>

@if(session('success'))
    <div class="message_ok"><p>{{ session('success') }}</p></div>
@endif
@if(session('error'))
    <div class="message_error"><p>{{ session('error') }}</p></div>
@endif

<p>
    <a href="{{ route('email_queues.unsent') }}">Zobrazit čekající e-maily</a>
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
        <a href="{{ route('email_queues.sent') }}" style="margin-left:6px;">Zrušit filtr</a>
    @endif
</form>

@if($canDelete && $emails->total() > 0)
<form method="POST" action="{{ route('email_queues.destroy-sent') }}" style="margin-bottom:1em;">
    @csrf @method('DELETE')
    @if($filterFrom)   <input type="hidden" name="from"    value="{{ $filterFrom }}"> @endif
    @if($filterTo)     <input type="hidden" name="to"      value="{{ $filterTo }}"> @endif
    @if($filterSubj)   <input type="hidden" name="subject" value="{{ $filterSubj }}"> @endif
    <button type="submit"
            style="background:none;border:none;cursor:pointer;padding:0;color:#c00;text-decoration:underline;"
            onclick="return confirm('Smazat {{ $emails->total() }} {{ $filterFrom || $filterTo || $filterSubj ? "filtrovaných" : "všechny odeslané" }} e-maily?')">
        Smazat {{ $filterFrom || $filterTo || $filterSubj ? 'filtrované' : 'všechny odeslané' }} ({{ $emails->total() }} ks)
    </button>
</form>
@endif

<table class="extended" cellspacing="0">
    <thead>
        <tr>
            <th>ID</th>
            <th>Od</th>
            <th>Komu</th>
            <th>Předmět</th>
            <th>Odesláno</th>
        </tr>
    </thead>
    <tbody>
        @forelse($emails as $email)
            <tr>
                <td>{{ $email->id }}</td>
                <td>{{ $email->from }}</td>
                <td>{{ $email->to }}</td>
                <td>{{ Str::limit($email->subject, 70) }}</td>
                <td>{{ \Carbon\Carbon::parse($email->access_time)->format('d.m.Y H:i') }}</td>
            </tr>
        @empty
            <tr><td colspan="5" style="text-align:center">Žádné odeslané e-maily.</td></tr>
        @endforelse
    </tbody>
</table>

<div style="margin-top:10px">{{ $emails->links() }}</div>
@endsection
