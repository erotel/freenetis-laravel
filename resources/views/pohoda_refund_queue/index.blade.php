@extends('layouts.app')

@section('title', 'Vratné faktury')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        Vratné faktury
    </div>
@endsection

@section('content')
<h2>Vratné faktury</h2>

{{-- Filtr --}}
<form method="GET" style="margin-bottom:1em;">
    <label>Stav:
        <select name="status">
            <option value=""        @selected($filterStatus === '')>— vše —</option>
            <option value="new"      @selected($filterStatus === 'new')>Nové</option>
            <option value="exported" @selected($filterStatus === 'exported')>Exportované</option>
        </select>
    </label>
    &nbsp;
    <button type="submit">Filtrovat</button>
    @if($filterStatus !== '')
        <a href="{{ route('pohoda-refund-queue.index') }}" style="margin-left:6px;">Zrušit filtr</a>
    @endif
</form>

<table class="extended" cellspacing="0">
    <thead>
        <tr>
            <th>ID</th>
            <th>Člen</th>
            <th>Doklad</th>
            <th>Částka</th>
            <th>Účet pro vrácení</th>
            <th>Vytvořeno</th>
            <th>Exportováno</th>
            <th>Stav</th>
        </tr>
    </thead>
    <tbody>
        @forelse($items as $item)
            <tr>
                <td>{{ $item->id }}</td>
                <td><a href="{{ route('members.show', $item->member_id) }}">{{ $item->member_name }}</a></td>
                <td>{{ $item->doc_number }}</td>
                <td style="text-align:right; white-space:nowrap;">
                    {{ number_format($item->amount, 2, ',', ' ') }}&nbsp;{{ $item->currency }}
                </td>
                <td style="font-family:monospace; font-size:0.9em;">{{ $item->refund_account }}</td>
                <td>{{ \Carbon\Carbon::parse($item->created_at)->format('d.m.Y') }}</td>
                <td>
                    @if($item->exported_at)
                        {{ \Carbon\Carbon::parse($item->exported_at)->format('d.m.Y H:i') }}
                    @else
                        —
                    @endif
                </td>
                <td>
                    @if($item->status === 'new')
                        <span style="color:#c60; font-weight:bold;">Nový</span>
                    @elseif($item->status === 'exported')
                        <span style="color:green;">Exportován</span>
                    @else
                        {{ $item->status }}
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="8" style="text-align:center">Žádné záznamy.</td></tr>
        @endforelse
    </tbody>
</table>

<div style="margin-top:10px">{{ $items->links() }}</div>
@endsection
