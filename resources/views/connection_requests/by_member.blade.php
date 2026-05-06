@extends('layouts.app')
@section('title', 'Žádosti o připojení — ' . $member->name)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('members.show', $member->id) }}">{{ $member->name }}</a> &raquo; Žádosti o připojení
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Žádosti o připojení — {{ $member->name }}</h2></div>

<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px">ID</th>
            <th style="width:140px">IP adresa</th>
            <th style="width:150px">MAC</th>
            <th>Subnet</th>
            <th style="width:90px">Stav</th>
            <th style="width:140px">Vytvořeno</th>
            <th style="width:140px">Rozhodnuto</th>
        </tr>
    </thead>
    <tbody>
        @forelse($items as $cr)
        @php
            $stateClass = match($cr->state) {
                \App\Models\ConnectionRequest::STATE_UNDECIDED => 'm-tag-amber',
                \App\Models\ConnectionRequest::STATE_APPROVED  => 'm-tag-green',
                default => 'm-tag-red',
            };
        @endphp
        <tr>
            <td><a class="m-link" href="{{ route('connection_requests.show', $cr->id) }}">{{ $cr->id }}</a></td>
            <td style="font-family:monospace;font-size:14px">{{ $cr->ip_address }}</td>
            <td style="font-family:monospace;font-size:14px">{{ $cr->mac_address }}</td>
            <td>{{ $cr->subnet?->name ?? '—' }}</td>
            <td><span class="m-tag {{ $stateClass }}">{{ $cr->stateName() }}</span></td>
            <td style="font-size:14px">{{ \Carbon\Carbon::parse($cr->created_at)->format('d.m.Y H:i') }}</td>
            <td style="font-size:14px">
                {{ $cr->decided_at ? \Carbon\Carbon::parse($cr->decided_at)->format('d.m.Y H:i') : '—' }}
            </td>
        </tr>
        @empty
        <tr><td colspan="7" style="text-align:center;color:#aaa;padding:2rem">Žádné žádosti.</td></tr>
        @endforelse
    </tbody>
</table>
</div>
</div>
@endsection
