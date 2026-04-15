@extends('layouts.app')

@section('title', 'Žádosti o připojení — ' . $member->name)

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('members.show', $member->id) }}">{{ $member->name }}</a> &raquo;
        Žádosti o připojení
    </div>
@endsection

@section('content')
<h2>Žádosti o připojení — {{ $member->name }}</h2>

@if(session('success'))
    <div style="color:green; margin-bottom:1em;">{{ session('success') }}</div>
@endif
@if(session('warning'))
    <div style="color:#c60; margin-bottom:1em;">{{ session('warning') }}</div>
@endif

<table class="extended" cellspacing="0">
    <thead>
        <tr>
            <th>ID</th>
            <th>IP adresa</th>
            <th>MAC</th>
            <th>Subnet</th>
            <th>Stav</th>
            <th>Vytvořeno</th>
            <th>Rozhodnuto</th>
        </tr>
    </thead>
    <tbody>
        @forelse($items as $cr)
            <tr>
                <td><a href="{{ route('connection_requests.show', $cr->id) }}">{{ $cr->id }}</a></td>
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
                <td style="white-space:nowrap;">
                    {{ $cr->decided_at ? \Carbon\Carbon::parse($cr->decided_at)->format('d.m.Y H:i') : '—' }}
                </td>
            </tr>
        @empty
            <tr><td colspan="7" style="text-align:center;">Žádné žádosti.</td></tr>
        @endforelse
    </tbody>
</table>
@endsection
