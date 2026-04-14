@extends('layouts.app')

@section('title', 'Aktivní přesměrování')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        Aktivní přesměrování
    </div>
@endsection

@section('content')
<h2>Aktivní přesměrování</h2>

@if(session('success'))
    <div class="message_ok"><p>{{ session('success') }}</p></div>
@endif
@if(session('warning'))
    <div class="message_warning"><p>{{ session('warning') }}</p></div>
@endif

{{-- Filtr --}}
<form method="GET" style="margin-bottom:1em;">
    <label>IP: <input type="text" name="ip" value="{{ $filterIp }}" style="width:130px;"></label>
    &nbsp;
    <label>Člen: <input type="text" name="member" value="{{ $filterMember }}" style="width:150px;"></label>
    &nbsp;
    <label>Typ:
        <select name="type">
            <option value="">— vše —</option>
            @foreach($typeLabels as $val => $label)
                <option value="{{ $val }}" @selected($filterType == $val)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
    &nbsp;
    <button type="submit">Filtrovat</button>
    @if($filterIp || $filterMember || $filterType)
        <a href="{{ route('redirects.index') }}" style="margin-left:6px;">Zrušit filtr</a>
    @endif
</form>

<table class="extended" cellspacing="0">
    <thead>
        <tr>
            <th>IP adresa</th>
            <th>Člen</th>
            <th>Zpráva</th>
            <th>Typ</th>
            <th>Datum</th>
            <th>Komentář</th>
            @if($canDelete) <th>Akce</th> @endif
        </tr>
    </thead>
    <tbody>
        @forelse($redirections as $r)
            <tr>
                <td>{{ $r->ip_address }}</td>
                <td>
                    @if($r->member_id)
                        <a href="{{ route('members.show', $r->member_id) }}">{{ $r->member_name }}</a>
                    @else
                        —
                    @endif
                </td>
                <td>{{ $r->msg_name }}</td>
                <td><small>{{ $typeLabels[$r->msg_type] ?? $r->msg_type }}</small></td>
                <td>{{ \Carbon\Carbon::parse($r->datetime)->format('d.m.Y H:i') }}</td>
                <td>{{ $r->comment ?? '—' }}</td>
                @if($canDelete)
                    <td>
                        <form method="POST"
                              action="{{ route('redirects.delete', [$r->ip_address_id, $r->message_id]) }}"
                              style="display:inline">
                            @csrf @method('DELETE')
                            <button type="submit"
                                    style="background:none;border:none;cursor:pointer;padding:0;color:#c00;text-decoration:underline;"
                                    onclick="return confirm('Zrušit přesměrování {{ $r->ip_address }}?')">Zrušit</button>
                        </form>
                    </td>
                @endif
            </tr>
        @empty
            <tr><td colspan="{{ $canDelete ? 7 : 6 }}" style="text-align:center">Žádná aktivní přesměrování.</td></tr>
        @endforelse
    </tbody>
</table>

<div style="margin-top:10px">{{ $redirections->links() }}</div>
@endsection
