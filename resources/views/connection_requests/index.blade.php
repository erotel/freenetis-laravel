@extends('layouts.app')

@section('title', 'Žádosti o připojení')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">Žádosti o připojení</div>
@endsection

@section('content')
<h2>Žádosti o připojení</h2>

@if(session('success'))
    <div style="color:green; margin-bottom:1em;">{{ session('success') }}</div>
@endif

<form method="GET" style="margin-bottom:1em;">
    <label>Stav:
        <select name="state">
            <option value="" @selected($filterState === '')>— vše —</option>
            @foreach($stateLabels as $val => $label)
                <option value="{{ $val }}" @selected($filterState === (string)$val)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
    &nbsp;
    <label>IP adresa: <input type="text" name="ip_address" value="{{ $filterIp }}" style="width:130px;"></label>
    &nbsp;
    <button type="submit">Filtrovat</button>
    @if($filterState !== '' || $filterIp !== '')
        <a href="{{ route('connection_requests.index') }}" style="margin-left:6px;">Zrušit</a>
    @endif
</form>

<table class="extended" cellspacing="0">
    <thead>
        <tr>
            <th>ID</th>
            <th>Člen</th>
            <th>IP adresa</th>
            <th>MAC</th>
            <th>Subnet</th>
            <th>Stav</th>
            <th>Vytvořeno</th>
            @if($canEdit) <th>Akce</th> @endif
        </tr>
    </thead>
    <tbody>
        @forelse($items as $cr)
            <tr>
                <td><a href="{{ route('connection_requests.show', $cr->id) }}">{{ $cr->id }}</a></td>
                <td>
                    @if($cr->member)
                        <a href="{{ route('members.show', $cr->member_id) }}">{{ $cr->member->name }}</a>
                    @else —
                    @endif
                </td>
                <td>{{ $cr->ip_address }}</td>
                <td style="font-family:monospace;">{{ $cr->mac_address }}</td>
                <td>{{ $cr->subnet?->name ?? '—' }}</td>
                <td>
                    @if($cr->state === \App\Models\ConnectionRequest::STATE_UNDECIDED)
                        <span style="color:#c60; font-weight:bold;">{{ $cr->stateName() }}</span>
                    @elseif($cr->state === \App\Models\ConnectionRequest::STATE_APPROVED)
                        <span style="color:green;">{{ $cr->stateName() }}</span>
                    @else
                        <span style="color:#c00;">{{ $cr->stateName() }}</span>
                    @endif
                </td>
                <td style="white-space:nowrap;">{{ \Carbon\Carbon::parse($cr->created_at)->format('d.m.Y H:i') }}</td>
                @if($canEdit)
                <td style="white-space:nowrap;">
                    @if($cr->state === \App\Models\ConnectionRequest::STATE_UNDECIDED)
                        <a href="{{ route('devices.create_from_cr', $cr->id) }}">Schválit</a>
                        &nbsp;|&nbsp;
                        <form method="POST" action="{{ route('connection_requests.reject', $cr->id) }}"
                              style="display:inline"
                              onsubmit="return confirm('Zamítnout žádost?')">
                            @csrf @method('DELETE')
                            <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;color:#c00;text-decoration:underline;">Zamítnout</button>
                        </form>
                    @else
                        —
                    @endif
                </td>
                @endif
            </tr>
        @empty
            <tr><td colspan="{{ $canEdit ? 8 : 7 }}" style="text-align:center;">Žádné záznamy.</td></tr>
        @endforelse
    </tbody>
</table>

<div style="margin-top:10px;">{{ $items->links() }}</div>
@endsection
