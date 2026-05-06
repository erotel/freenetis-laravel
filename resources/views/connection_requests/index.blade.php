@extends('layouts.app')
@section('title', 'Žádosti o připojení')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">Žádosti o připojení</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Žádosti o připojení</h2></div>
<div class="m-subtitle">Celkem: {{ $items->total() }} záznamů</div>

<div class="m-card" style="margin-bottom:16px;padding:14px 1.25rem">
    <form method="GET" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">
        <div>
            <div class="m-form-label">Stav</div>
            <select class="m-form-select" style="width:140px" name="state" onchange="this.form.submit()">
                <option value="" @selected($filterState === '')>— vše —</option>
                @foreach($stateLabels as $val => $label)
                    <option value="{{ $val }}" @selected($filterState === (string)$val)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <div class="m-form-label">IP adresa</div>
            <input class="m-form-input" type="text" name="ip_address" value="{{ $filterIp }}" style="width:140px;font-family:monospace">
        </div>
        <div style="display:flex;gap:6px;padding-bottom:1px">
            <button class="m-btn m-btn-primary" type="submit">Filtrovat</button>
            @if($filterState !== '' || $filterIp !== '')
            <a class="m-btn" href="{{ route('connection_requests.index') }}">Zrušit</a>
            @endif
        </div>
    </form>
</div>

<div style="margin-bottom:8px">{{ $items->links() }}</div>

<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px">ID</th>
            <th>Člen</th>
            <th style="width:140px">IP adresa</th>
            <th style="width:150px">MAC</th>
            <th>Subnet</th>
            <th style="width:90px">Stav</th>
            <th style="width:140px">Vytvořeno</th>
            @if($canEdit)<th style="width:120px">Akce</th>@endif
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
            <td>
                @if($cr->member) <a class="m-link" href="{{ route('members.show', $cr->member_id) }}">{{ $cr->member->name }}</a>
                @else — @endif
            </td>
            <td style="font-family:monospace;font-size:14px">{{ $cr->ip_address }}</td>
            <td style="font-family:monospace;font-size:14px">{{ $cr->mac_address }}</td>
            <td>{{ $cr->subnet?->name ?? '—' }}</td>
            <td><span class="m-tag {{ $stateClass }}">{{ $cr->stateName() }}</span></td>
            <td style="font-size:14px">{{ \Carbon\Carbon::parse($cr->created_at)->format('d.m.Y H:i') }}</td>
            @if($canEdit)
            <td>
                @if($cr->state === \App\Models\ConnectionRequest::STATE_UNDECIDED)
                <div style="display:flex;gap:6px">
                    <a class="m-link-sm" href="{{ route('devices.create_from_cr', $cr->id) }}">Schválit</a>
                    <form method="POST" action="{{ route('connection_requests.reject', $cr->id) }}" style="display:inline"
                          onsubmit="return confirm('Zamítnout žádost?')">
                        @csrf @method('DELETE')
                        <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;font-size:14px;color:#c0392b">Zamítnout</button>
                    </form>
                </div>
                @else —
                @endif
            </td>
            @endif
        </tr>
        @empty
        <tr><td colspan="{{ $canEdit ? 8 : 7 }}" style="text-align:center;color:#aaa;padding:2rem">Žádné záznamy.</td></tr>
        @endforelse
    </tbody>
</table>
</div>

<div style="margin-top:12px">{{ $items->links() }}</div>
</div>
@endsection
