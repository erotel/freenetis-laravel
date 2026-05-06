@extends('layouts.app')
@section('title', 'Aktivní přesměrování')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">Aktivní přesměrování</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Aktivní přesměrování</h2></div>
<div class="m-subtitle">Celkem: {{ $redirections->total() }} záznamů</div>

<div class="m-card" style="margin-bottom:16px;padding:14px 1.25rem">
    <form method="GET" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">
        <div>
            <div class="m-form-label">IP</div>
            <input class="m-form-input" type="text" name="ip" value="{{ $filterIp }}" style="width:140px;font-family:monospace">
        </div>
        <div>
            <div class="m-form-label">Člen</div>
            <input class="m-form-input" type="text" name="member" value="{{ $filterMember }}" style="width:160px">
        </div>
        <div>
            <div class="m-form-label">Typ</div>
            <select class="m-form-select" style="width:150px" name="type" onchange="this.form.submit()">
                <option value="">— vše —</option>
                @foreach($typeLabels as $val => $label)
                    <option value="{{ $val }}" @selected($filterType == $val)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div style="display:flex;gap:6px;padding-bottom:1px">
            <button class="m-btn m-btn-primary" type="submit">Filtrovat</button>
            @if($filterIp || $filterMember || $filterType)
            <a class="m-btn" href="{{ route('redirects.index') }}">Zrušit filtr</a>
            @endif
        </div>
    </form>
</div>

<div style="margin-bottom:8px">{{ $redirections->links() }}</div>

<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:140px">IP adresa</th>
            <th>Člen</th>
            <th>Zpráva</th>
            <th style="width:110px">Typ</th>
            <th style="width:130px">Datum</th>
            <th>Komentář</th>
            @if($canDelete)<th style="width:70px">Akce</th>@endif
        </tr>
    </thead>
    <tbody>
        @forelse($redirections as $r)
        <tr>
            <td style="font-family:monospace;font-size:14px">{{ $r->ip_address }}</td>
            <td>
                @if($r->member_id) <a class="m-link" href="{{ route('members.show', $r->member_id) }}">{{ $r->member_name }}</a>
                @else — @endif
            </td>
            <td>{{ $r->msg_name }}</td>
            <td><span class="m-tag m-tag-amber" style="font-size:13px">{{ $typeLabels[$r->msg_type] ?? $r->msg_type }}</span></td>
            <td style="font-size:14px">{{ \Carbon\Carbon::parse($r->datetime)->format('d.m.Y H:i') }}</td>
            <td style="font-size:14px">{{ $r->comment ?? '—' }}</td>
            @if($canDelete)
            <td>
                <form method="POST" action="{{ route('redirects.delete', [$r->ip_address_id, $r->message_id]) }}" style="display:inline">
                    @csrf @method('DELETE')
                    <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;font-size:14px;color:#c0392b"
                            onclick="return confirm('Zrušit přesměrování {{ $r->ip_address }}?')">Zrušit</button>
                </form>
            </td>
            @endif
        </tr>
        @empty
        <tr><td colspan="{{ $canDelete ? 7 : 6 }}" style="text-align:center;color:#aaa;padding:2rem">Žádná aktivní přesměrování.</td></tr>
        @endforelse
    </tbody>
</table>
</div>

<div style="margin-top:12px">{{ $redirections->links() }}</div>
</div>
@endsection
