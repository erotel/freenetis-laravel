@extends('layouts.app')
@section('title', 'Odeslané e-maily')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">Odeslané e-maily</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Odeslané e-maily</h2></div>
<div class="m-subtitle">
    Celkem: {{ $emails->total() }} záznamů
    @if(!$loadAll) <span style="color:#888">(zobrazeno z posledních 200)</span> @endif
</div>

<div class="m-actions">
    <a class="m-btn" href="{{ route('email_queues.unsent') }}">Čekající e-maily</a>
    @if($loadAll)
        <a class="m-btn" href="{{ route('email_queues.sent', array_filter(['from'=>$filterFrom,'to'=>$filterTo,'subject'=>$filterSubj])) }}">← Posledních 200</a>
    @else
        <a class="m-btn m-btn-primary" href="{{ route('email_queues.sent', array_filter(['all'=>1,'from'=>$filterFrom,'to'=>$filterTo,'subject'=>$filterSubj])) }}">Načíst vše (i starší)</a>
    @endif
    @if($canDelete && $emails->total() > 0)
    <form method="POST" action="{{ route('email_queues.destroy-sent') }}" style="display:inline">
        @csrf @method('DELETE')
        @if($filterFrom)   <input type="hidden" name="from"    value="{{ $filterFrom }}"> @endif
        @if($filterTo)     <input type="hidden" name="to"      value="{{ $filterTo }}"> @endif
        @if($filterSubj)   <input type="hidden" name="subject" value="{{ $filterSubj }}"> @endif
        <button class="m-btn m-btn-danger" type="submit"
                onclick="return confirm('Smazat {{ $emails->total() }} {{ $filterFrom || $filterTo || $filterSubj ? "filtrovaných" : "všechny odeslané" }} e-maily?')">
            Smazat {{ $filterFrom || $filterTo || $filterSubj ? 'filtrované' : 'všechny odeslané' }} ({{ $emails->total() }} ks)
        </button>
    </form>
    @endif
</div>

<div class="m-card" style="margin-bottom:16px;padding:14px 1.25rem">
    <form method="GET" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">
        @if($loadAll) <input type="hidden" name="all" value="1"> @endif
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
            <a class="m-btn" href="{{ route('email_queues.sent', $loadAll ? ['all' => 1] : []) }}">Zrušit filtr</a>
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
            <th style="width:140px">Odesláno</th>
            <th style="width:70px">Akce</th>
        </tr>
    </thead>
    <tbody>
        @forelse($emails as $email)
        <tr>
            <td><a href="{{ route('email_queues.show', $email->id) }}">{{ $email->id }}</a></td>
            <td style="font-size:14px">{{ $email->from }}</td>
            <td style="font-size:14px">{{ $email->to }}</td>
            <td style="font-size:14px"><a href="{{ route('email_queues.show', $email->id) }}">{{ Str::limit($email->subject, 70) }}</a></td>
            <td style="font-size:14px">{{ \Carbon\Carbon::parse($email->access_time)->format('d.m.Y H:i') }}</td>
            <td><a href="{{ route('email_queues.show', $email->id) }}" style="font-size:14px">Zobrazit</a></td>
        </tr>
        @empty
        <tr><td colspan="6" style="text-align:center;color:#aaa;padding:2rem">Žádné odeslané e-maily.</td></tr>
        @endforelse
    </tbody>
</table>
</div>

<div style="margin-top:12px">{{ $emails->links() }}</div>
</div>
@endsection
