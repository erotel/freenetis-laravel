@extends('layouts.app')
@section('title', 'Prezence: ' . $meeting->title)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('meetings.index') }}">Schůze členů</a> &raquo; {{ $meeting->title }}
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>{{ $meeting->title }}</h2></div>
<div class="m-subtitle">
    {{ $meeting->held_on?->format('d.m.Y') }} · {{ $meeting->typeLabel() }} ·
    stav: <strong>{{ $meeting->statusLabel() }}</strong>
</div>

@if(session('success'))<div class="m-alert m-alert-success">{{ session('success') }}</div>@endif
@foreach($errors->all() as $e)<div class="m-alert m-alert-danger">{{ $e }}</div>@endforeach

{{-- Stav / akce schůze --}}
@if($canEdit)
<div class="m-actions">
    @if($meeting->status === 'planned')
        <form method="POST" action="{{ route('meetings.open', $meeting->id) }}" style="display:inline">@csrf
            <button class="m-btn m-btn-success" type="submit">Zahájit schůzi</button></form>
    @elseif($meeting->status === 'open')
        <form method="POST" action="{{ route('meetings.close', $meeting->id) }}" style="display:inline"
              onsubmit="return confirm('Uzavřít schůzi? Zapíše se docházka a spočítají kandidáti na degradaci.')">@csrf
            <button class="m-btn m-btn-danger" type="submit">Uzavřít schůzi</button></form>
    @endif
    <a class="m-btn" href="{{ route('meetings.presence-export', $meeting->id) }}">Export prezenční listiny (CSV)</a>
</div>
@endif

{{-- Usnášeníschopnost --}}
<div class="m-card" style="max-width:640px;margin-bottom:16px">
    <div class="m-card-title">Usnášeníschopnost</div>
    <div class="m-field"><span class="m-field-label">Seniorů celkem</span><span class="m-field-value">{{ $total }}</span></div>
    <div class="m-field"><span class="m-field-label">Přítomno osobně</span><span class="m-field-value">{{ $present }}</span></div>
    <div class="m-field"><span class="m-field-label">Zastoupeno plnou mocí</span><span class="m-field-value">{{ $proxied }}</span></div>
    <div class="m-field"><span class="m-field-label">Hlasů (do kvóra)</span><span class="m-field-value"><strong>{{ $counted }}</strong></span></div>
    <div class="m-field"><span class="m-field-label">Potřeba ({{ $meeting->quorum_percent }} %)</span><span class="m-field-value">{{ $quorumNeed }}</span></div>
    <div class="m-field">
        <span class="m-field-label">Stav</span>
        <span class="m-field-value">
            @if($quorumMet)<span class="m-tag m-tag-green">Usnášeníschopná</span>
            @else<span class="m-tag m-tag-red">NEusnášeníschopná</span>@endif
        </span>
    </div>
</div>

{{-- Kandidáti na degradaci (jen u uzavřené schůze) --}}
@if($meeting->status === 'closed' && count($degradeCandidates) && $canEdit)
<div class="m-card" style="margin-bottom:16px;border:1px solid #c0392b">
    <div class="m-card-title" style="color:#c0392b">Kandidáti na degradaci (3× po sobě nepřítomen)</div>
    <form method="POST" action="{{ route('meetings.degrade', $meeting->id) }}"
          onsubmit="return confirm('Odebrat vybraným členům hlasovací právo?')">@csrf
        @foreach($degradeCandidates as $c)
        <label style="display:block;padding:3px 0">
            <input type="checkbox" name="member_ids[]" value="{{ $c->id }}" checked> {{ $c->name }}
        </label>
        @endforeach
        <button class="m-btn m-btn-danger" type="submit" style="margin-top:8px">Degradovat vybrané</button>
    </form>
</div>
@endif

{{-- Prezence seniorů --}}
<div class="m-section">Prezence ({{ $total }} seniorů)</div>
<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead><tr>
        <th>Člen</th><th style="width:120px">Stav</th><th>Plná moc</th><th style="width:80px">Příchod</th>
    </tr></thead>
    <tbody>
        @foreach($rows as $r)
        <tr @if($r['at_risk'] && !$r['present'] && !$r['proxied']) style="background:#fdecea" @endif>
            <td>
                <a class="m-link" href="{{ route('members.show', $r['member']->id) }}">{{ $r['member']->name }}</a>
                @if($r['at_risk'])<span class="m-tag m-tag-red" title="{{ $r['prior_absences'] }}× po sobě nepřítomen — pokud nepřijde ani teď, hrozí degradace">⚠ {{ $r['prior_absences'] }}× chyběl</span>@endif
                @if($r['proxy_load'] > 0)<small style="color:#888">nese {{ $r['proxy_load'] }} plné moci</small>@endif
            </td>
            <td>
                @if($r['present'])<span class="m-tag m-tag-green">Přítomen</span>
                @elseif($r['proxied'])<span class="m-tag m-tag-blue">Zastoupen</span>
                @else<span class="m-tag">Nepřítomen</span>@endif
            </td>
            <td>
                @if($canEdit && $meeting->status !== 'closed')
                    {{-- Přítomnost toggle --}}
                    <form method="POST" action="{{ route('meetings.checkin', [$meeting->id, $r['member']->id]) }}" style="display:inline">@csrf
                        <button class="m-btn m-btn-sm {{ $r['present'] ? '' : 'm-btn-success' }}" type="submit">
                            {{ $r['present'] ? 'Zrušit přítomnost' : 'Přítomen' }}</button>
                    </form>
                    {{-- Plná moc (jen když není osobně přítomen) --}}
                    @unless($r['present'])
                    <form method="POST" action="{{ route('meetings.proxy', [$meeting->id, $r['member']->id]) }}" style="display:inline-flex;gap:4px;margin-left:6px">@csrf
                        <select class="m-form-select m-form-sm" name="proxy_holder_id" onchange="this.form.submit()">
                            <option value="">— zastupuje —</option>
                            @foreach($rows as $h)
                                @if($h['present'] && $h['member']->id !== $r['member']->id)
                                <option value="{{ $h['member']->id }}" @selected($r['proxy_holder']===$h['member']->id)>{{ $h['member']->name }}</option>
                                @endif
                            @endforeach
                        </select>
                    </form>
                    @endunless
                @else
                    @if($r['proxied'])
                        @php $holder = collect($rows)->firstWhere('member.id', $r['proxy_holder']); @endphp
                        <small>zast. {{ $holder['member']->name ?? ('#'.$r['proxy_holder']) }}</small>
                    @else — @endif
                @endif
            </td>
            <td>{{ $r['checked_in_at']?->format('H:i') ?? '—' }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
</div>
</div>
@endsection
