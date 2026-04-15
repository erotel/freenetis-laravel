@extends('layouts.app')

@section('title', 'SMS zprávy')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        SMS zprávy
    </div>
@endsection

@section('content')
<h2>SMS zprávy</h2>

@if($canSend)
<div style="margin-bottom:0.5em;">
    <a href="{{ route('sms_messages.create') }}">+ Odeslat SMS</a>
</div>
@endif

@if(session('success'))
    <div class="alert alert-success" style="color:green; margin-bottom:1em;">{{ session('success') }}</div>
@endif

{{-- Filtr --}}
<form method="GET" style="margin-bottom:1em;">
    <label>Typ:
        <select name="type">
            <option value="" @selected($filterType === '')>— vše —</option>
            <option value="0" @selected($filterType === '0')>Přijatá</option>
            <option value="1" @selected($filterType === '1')>Odeslaná</option>
        </select>
    </label>
    &nbsp;
    <label>Stav:
        <select name="state">
            <option value="" @selected($filterState === '')>— vše —</option>
            <option value="0" @selected($filterState === '0')>Nepřečtená / OK</option>
            <option value="1" @selected($filterState === '1')>Přečtená / Čekající</option>
            <option value="2" @selected($filterState === '2')>Chyba</option>
        </select>
    </label>
    &nbsp;
    <button type="submit">Filtrovat</button>
    @if($filterType !== '' || $filterState !== '')
        <a href="{{ route('sms_messages.index') }}" style="margin-left:6px;">Zrušit filtr</a>
    @endif
</form>

@if($canDelete)
<form method="POST" action="{{ route('sms_messages.destroy-unsent') }}"
      onsubmit="return confirm('Smazat všechny neodeslané SMS?')"
      style="margin-bottom:1em;">
    @csrf @method('DELETE')
    <button type="submit">Smazat čekající</button>
</form>
@endif

<table class="extended" cellspacing="0">
    <thead>
        <tr>
            <th>ID</th>
            <th>Typ</th>
            <th>Stav</th>
            <th>Odesílatel</th>
            <th>Příjemce</th>
            <th>Text</th>
            <th>Uživatel</th>
            <th>Datum</th>
        </tr>
    </thead>
    <tbody>
        @forelse($items as $sms)
            <tr>
                <td><a href="{{ route('sms_messages.show', $sms->id) }}">{{ $sms->id }}</a></td>
                <td>
                    @if($sms->type == 0)
                        <span style="color:#060;">{{ $sms->typeName() }}</span>
                    @else
                        <span style="color:#00c;">{{ $sms->typeName() }}</span>
                    @endif
                </td>
                <td>
                    @php $state = $sms->stateName(); @endphp
                    @if(in_array($state, ['Čekající', 'Nepřečtená']))
                        <span style="color:#c60; font-weight:bold;">{{ $state }}</span>
                    @elseif($state === 'Chyba')
                        <span style="color:#c00; font-weight:bold;">{{ $state }}</span>
                    @else
                        {{ $state }}
                    @endif
                </td>
                <td style="white-space:nowrap;">{{ $sms->sender }}</td>
                <td style="white-space:nowrap;">{{ $sms->receiver }}</td>
                <td>
                    <a href="{{ route('sms_messages.show', $sms->id) }}">
                        {{ \Illuminate\Support\Str::limit($sms->text, 60) }}
                    </a>
                </td>
                <td>
                    @if($sms->user)
                        <a href="{{ route('users.show', $sms->user_id) }}">{{ $sms->user->login }}</a>
                    @else
                        —
                    @endif
                </td>
                <td style="white-space:nowrap;">{{ \Carbon\Carbon::parse($sms->stamp)->format('d.m.Y H:i') }}</td>
            </tr>
        @empty
            <tr><td colspan="8" style="text-align:center;">Žádné záznamy.</td></tr>
        @endforelse
    </tbody>
</table>

<div style="margin-top:10px;">{{ $items->links() }}</div>
@endsection
