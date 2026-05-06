@extends('layouts.app')
@section('title', 'Chyby a logy')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">Chyby a logy</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Chyby a logy</h2></div>
<div class="m-subtitle">Celkem: {{ $logs->total() }} záznamů</div>

<div class="m-card" style="margin-bottom:16px;padding:14px 1.25rem">
    <form method="GET" action="{{ route('log_queues.index') }}" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">
        <div>
            <div class="m-form-label">Typ</div>
            <select class="m-form-select" style="width:150px" name="type">
                <option value="">— vše —</option>
                @foreach($typeNames as $val => $label)
                    <option value="{{ $val }}" @selected($filterType == (string)$val)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <div class="m-form-label">Stav</div>
            <select class="m-form-select" style="width:130px" name="state">
                <option value="">— vše —</option>
                @foreach($stateNames as $val => $label)
                    <option value="{{ $val }}" @selected($filterState == (string)$val)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div style="display:flex;gap:6px;padding-bottom:1px">
            <button class="m-btn m-btn-primary" type="submit">Filtrovat</button>
            <a class="m-btn" href="{{ route('log_queues.index') }}">Resetovat</a>
        </div>
    </form>
</div>

@if($canEdit)
<div class="m-actions">
    <form method="POST" action="{{ route('log_queues.close-all') }}">
        @csrf
        @if($filterType !== '') <input type="hidden" name="type" value="{{ $filterType }}"> @endif
        @if($filterState !== '') <input type="hidden" name="state" value="{{ $filterState }}"> @endif
        <button class="m-btn m-btn-danger" type="submit"
                onclick="return confirm('Uzavřít všechny nové záznamy (dle aktuálního filtru)?')">
            Uzavřít vše
        </button>
    </form>
</div>
@endif

<div style="margin-bottom:8px">{{ $logs->links() }}</div>

<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px">ID</th>
            <th style="width:100px">Typ</th>
            <th style="width:80px">Stav</th>
            <th style="width:140px">Zaznamenáno</th>
            <th>Popis</th>
            <th style="width:120px">Uzavřeno</th>
            <th style="width:100px">Uzavřel</th>
            <th style="width:80px">Akce</th>
        </tr>
    </thead>
    <tbody>
        @forelse($logs as $log)
        <tr>
            <td>{{ $log->id }}</td>
            <td>
                <span style="background:{{ $typeColors[$log->type] ?? '#888' }};color:#fff;font-size:13px;padding:2px 7px;border-radius:10px;white-space:nowrap">
                    {{ $typeNames[$log->type] ?? $log->type }}
                </span>
            </td>
            <td>
                @if($log->state == 0)
                    <span class="m-tag m-tag-red">Nový</span>
                @else
                    <span class="m-tag m-tag-gray">Uzavřený</span>
                @endif
            </td>
            <td style="font-size:14px">{{ $log->created_at }}</td>
            <td style="font-size:14px;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                {{ \Illuminate\Support\Str::limit($log->description, 100) }}
            </td>
            <td style="font-size:14px">{{ $log->closed_at ?? '—' }}</td>
            <td style="font-size:14px">{{ $log->closed_by_name ?? '—' }}</td>
            <td>
                <div style="display:flex;gap:6px">
                    <a class="m-link-sm" href="{{ route('log_queues.show', $log->id) }}">Detail</a>
                    @if($canEdit && $log->state == 0)
                    <form method="POST" action="{{ route('log_queues.close', $log->id) }}" style="display:inline">
                        @csrf
                        <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;font-size:14px;color:#185FA5"
                                onclick="return confirm('Uzavřít tento záznam?')">Uzavřít</button>
                    </form>
                    @endif
                </div>
            </td>
        </tr>
        @empty
        <tr><td colspan="8" style="text-align:center;color:#aaa;padding:2rem">Žádné záznamy.</td></tr>
        @endforelse
    </tbody>
</table>
</div>

<div style="margin-top:12px">{{ $logs->links() }}</div>
</div>
@endsection
