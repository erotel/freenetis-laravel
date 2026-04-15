@extends('layouts.app')

@section('title', 'Žádost o připojení #' . $cr->id)

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('connection_requests.index') }}">Žádosti o připojení</a> &raquo;
        #{{ $cr->id }}
    </div>
@endsection

@section('content')
<h2>Žádost o připojení #{{ $cr->id }}</h2>

@if(session('success'))
    <div style="color:green; margin-bottom:1em;">{{ session('success') }}</div>
@endif

@if($canEdit)
<div style="margin-bottom:1em;">
    <a href="{{ route('connection_requests.approve', $cr->id) }}">
        <img src="{{ asset('media/images/icons/accept.png') }}" alt=""> Schválit
    </a>
    &nbsp;&nbsp;
    <form method="POST" action="{{ route('connection_requests.reject', $cr->id) }}"
          style="display:inline"
          onsubmit="return confirm('Zamítnout žádost #{{ $cr->id }}?')">
        @csrf @method('DELETE')
        <button type="submit" class="icon-button" style="color:#c00;">
            <img src="{{ asset('media/images/icons/delete.png') }}" alt=""> Zamítnout
        </button>
    </form>
</div>
@endif

<table class="extended" cellspacing="0">
    <thead>
        <tr><th colspan="2">Detail žádosti</th></tr>
    </thead>
    <tbody>
        <tr><th>ID</th><td>{{ $cr->id }}</td></tr>
        <tr>
            <th>Člen</th>
            <td>
                @if($cr->member)
                    <a href="{{ route('members.show', $cr->member_id) }}">{{ $cr->member->name }}</a>
                @else —
                @endif
            </td>
        </tr>
        <tr><th>IP adresa</th><td>{{ $cr->ip_address }}</td></tr>
        <tr><th>MAC adresa</th><td style="font-family:monospace;">{{ $cr->mac_address }}</td></tr>
        <tr>
            <th>Subnet</th>
            <td>
                @if($cr->subnet)
                    <a href="{{ route('subnets.show', $cr->subnet_id) }}">{{ $cr->subnet->name }}</a>
                @else —
                @endif
            </td>
        </tr>
        <tr><th>Typ zařízení</th><td>{{ $cr->deviceType?->value ?? '—' }}</td></tr>
        <tr><th>Šablona zařízení</th><td>{{ $cr->deviceTemplate?->name ?? '—' }}</td></tr>
        <tr>
            <th>Stav</th>
            <td>
                @if($cr->state === \App\Models\ConnectionRequest::STATE_UNDECIDED)
                    <span style="color:#c60; font-weight:bold;">{{ $cr->stateName() }}</span>
                @elseif($cr->state === \App\Models\ConnectionRequest::STATE_APPROVED)
                    <span style="color:green;">{{ $cr->stateName() }}</span>
                @else
                    <span style="color:#c00;">{{ $cr->stateName() }}</span>
                @endif
            </td>
        </tr>
        <tr>
            <th>Přidal</th>
            <td>{{ $cr->addedUser?->login ?? '—' }}</td>
        </tr>
        <tr>
            <th>Vytvořeno</th>
            <td>{{ \Carbon\Carbon::parse($cr->created_at)->format('d.m.Y H:i') }}</td>
        </tr>
        @if($cr->decided_at)
        <tr>
            <th>Rozhodl</th>
            <td>{{ $cr->decidedUser?->login ?? '—' }}</td>
        </tr>
        <tr>
            <th>Rozhodnuto</th>
            <td>{{ \Carbon\Carbon::parse($cr->decided_at)->format('d.m.Y H:i') }}</td>
        </tr>
        @endif
        @if($cr->comment)
        <tr><th>Komentář</th><td>{{ $cr->comment }}</td></tr>
        @endif
        @if($cr->device_id)
        <tr>
            <th>Zařízení</th>
            <td><a href="{{ route('devices.show', $cr->device_id) }}">{{ $cr->device?->name ?? $cr->device_id }}</a></td>
        </tr>
        @endif
    </tbody>
</table>

<div style="margin-top:1em;">
    <a href="{{ route('connection_requests.index') }}">&laquo; Zpět na seznam</a>
</div>
@endsection
