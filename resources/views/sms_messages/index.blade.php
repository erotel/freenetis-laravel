@extends('layouts.app')
@section('title', 'SMS zprávy')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">SMS zprávy</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>SMS zprávy</h2></div>
<div class="m-subtitle">Celkem: {{ $items->total() }} záznamů</div>

<div class="m-actions">
    @if($canSend) <a class="m-btn m-btn-success" href="{{ route('sms_messages.create') }}">+ Odeslat SMS</a> @endif
    @if($canDelete)
    <form method="POST" action="{{ route('sms_messages.destroy-unsent') }}" style="display:inline"
          onsubmit="return confirm('Smazat všechny neodeslané SMS?')">
        @csrf @method('DELETE')
        <button class="m-btn m-btn-danger" type="submit">Smazat čekající</button>
    </form>
    @endif
</div>

<div class="m-card" style="margin-bottom:16px;padding:14px 1.25rem">
    <form method="GET" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">
        <div>
            <div class="m-form-label">Typ</div>
            <select class="m-form-select" style="width:120px" name="type" onchange="this.form.submit()">
                <option value="" @selected($filterType === '')>— vše —</option>
                <option value="0" @selected($filterType === '0')>Přijatá</option>
                <option value="1" @selected($filterType === '1')>Odeslaná</option>
            </select>
        </div>
        <div>
            <div class="m-form-label">Stav</div>
            <select class="m-form-select" style="width:130px" name="state" onchange="this.form.submit()">
                <option value="" @selected($filterState === '')>— vše —</option>
                <option value="0" @selected($filterState === '0')>Nepřečtená / OK</option>
                <option value="1" @selected($filterState === '1')>Přečtená / Čekající</option>
                <option value="2" @selected($filterState === '2')>Chyba</option>
            </select>
        </div>
        @if($filterType !== '' || $filterState !== '')
        <div style="padding-bottom:1px">
            <a class="m-btn" href="{{ route('sms_messages.index') }}">Zrušit filtr</a>
        </div>
        @endif
    </form>
</div>

<div style="margin-bottom:8px">{{ $items->links() }}</div>

<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px">ID</th>
            <th style="width:80px">Typ</th>
            <th style="width:90px">Stav</th>
            <th>Odesílatel</th>
            <th>Příjemce</th>
            <th>Text</th>
            <th style="width:100px">Uživatel</th>
            <th style="width:130px">Datum</th>
        </tr>
    </thead>
    <tbody>
        @forelse($items as $sms)
        @php
            $state = $sms->stateName();
            $stateClass = in_array($state, ['Čekající', 'Nepřečtená']) ? 'm-tag-amber' : ($state === 'Chyba' ? 'm-tag-red' : 'm-tag-gray');
            $typeClass  = $sms->type == 0 ? 'm-tag-green' : 'm-tag-blue';
        @endphp
        <tr>
            <td><a class="m-link" href="{{ route('sms_messages.show', $sms->id) }}">{{ $sms->id }}</a></td>
            <td><span class="m-tag {{ $typeClass }}" style="font-size:13px">{{ $sms->typeName() }}</span></td>
            <td><span class="m-tag {{ $stateClass }}" style="font-size:13px">{{ $state }}</span></td>
            <td style="font-family:monospace;font-size:14px">{{ $sms->sender }}</td>
            <td style="font-family:monospace;font-size:14px">{{ $sms->receiver }}</td>
            <td>
                <a class="m-link" href="{{ route('sms_messages.show', $sms->id) }}">
                    {{ \Illuminate\Support\Str::limit($sms->text, 60) }}
                </a>
            </td>
            <td>
                @if($sms->user) <a class="m-link" href="{{ route('users.show', $sms->user_id) }}">{{ $sms->user->login }}</a>
                @else — @endif
            </td>
            <td style="font-size:14px">{{ \Carbon\Carbon::parse($sms->stamp)->format('d.m.Y H:i') }}</td>
        </tr>
        @empty
        <tr><td colspan="8" style="text-align:center;color:#aaa;padding:2rem">Žádné záznamy.</td></tr>
        @endforelse
    </tbody>
</table>
</div>

<div style="margin-top:12px">{{ $items->links() }}</div>
</div>
@endsection
