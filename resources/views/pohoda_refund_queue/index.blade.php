@extends('layouts.app')
@section('title', 'Vratné faktury')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">Vratné faktury</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Vratné faktury</h2></div>
<div class="m-subtitle">Celkem: {{ $items->total() }} záznamů</div>

<div class="m-card" style="margin-bottom:16px;padding:14px 1.25rem">
    <form method="GET" style="display:flex;gap:10px;align-items:flex-end">
        <div>
            <div class="m-form-label">Stav</div>
            <select class="m-form-select" style="width:140px" name="status" onchange="this.form.submit()">
                <option value="" @selected($filterStatus === '')>— vše —</option>
                <option value="new" @selected($filterStatus === 'new')>Nové</option>
                <option value="exported" @selected($filterStatus === 'exported')>Exportované</option>
            </select>
        </div>
        @if($filterStatus !== '')
        <div style="padding-bottom:1px">
            <a class="m-btn" href="{{ route('pohoda-refund-queue.index') }}">Zrušit filtr</a>
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
            <th>Člen</th>
            <th style="width:100px">Doklad</th>
            <th style="width:110px;text-align:right">Částka</th>
            <th>Účet pro vrácení</th>
            <th style="width:100px">Vytvořeno</th>
            <th style="width:140px">Exportováno</th>
            <th style="width:90px">Stav</th>
        </tr>
    </thead>
    <tbody>
        @forelse($items as $item)
        <tr>
            <td>{{ $item->id }}</td>
            <td><a class="m-link" href="{{ route('members.show', $item->member_id) }}">{{ $item->member_name }}</a></td>
            <td>{{ $item->doc_number }}</td>
            <td style="text-align:right;font-family:monospace;font-size:14px">
                {{ number_format($item->amount, 2, ',', ' ') }} {{ $item->currency }}
            </td>
            <td style="font-family:monospace;font-size:14px">{{ $item->refund_account }}</td>
            <td style="font-size:14px">{{ \Carbon\Carbon::parse($item->created_at)->format('d.m.Y') }}</td>
            <td style="font-size:14px">
                @if($item->exported_at)
                    {{ \Carbon\Carbon::parse($item->exported_at)->format('d.m.Y H:i') }}
                @else —
                @endif
            </td>
            <td>
                @if($item->status === 'new')
                    <span class="m-tag m-tag-amber">Nový</span>
                @elseif($item->status === 'exported')
                    <span class="m-tag m-tag-green">Exportován</span>
                @else
                    {{ $item->status }}
                @endif
            </td>
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
