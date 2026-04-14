@extends('layouts.app')

@section('title', 'Chyby a logy')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        Chyby a logy
    </div>
@endsection

@section('content')
    <h2>Chyby a logy</h2>

    @if(session('success'))
        <div class="message_ok"><p>{{ session('success') }}</p></div>
    @endif
    @if(session('error'))
        <div class="message_error"><p>{{ session('error') }}</p></div>
    @endif

    {{-- Filter form --}}
    <form method="GET" action="{{ route('log_queues.index') }}" class="form" style="margin-bottom:10px">
        <table class="extended" cellspacing="0">
            <tbody>
                <tr>
                    <th><label for="type">Typ</label></th>
                    <td>
                        <select name="type" id="type">
                            <option value="">— vše —</option>
                            @foreach($typeNames as $val => $label)
                                <option value="{{ $val }}" @selected($filterType == (string)$val)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </td>
                    <th><label for="state">Stav</label></th>
                    <td>
                        <select name="state" id="state">
                            <option value="">— vše —</option>
                            @foreach($stateNames as $val => $label)
                                <option value="{{ $val }}" @selected($filterState == (string)$val)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </td>
                    <td>
                        <button type="submit">Filtrovat</button>
                        <a href="{{ route('log_queues.index') }}">Resetovat</a>
                    </td>
                </tr>
            </tbody>
        </table>
    </form>

    @if($canEdit)
        <form method="POST" action="{{ route('log_queues.close-all') }}" style="margin-bottom:10px">
            @csrf
            @if($filterType !== '')
                <input type="hidden" name="type" value="{{ $filterType }}">
            @endif
            @if($filterState !== '')
                <input type="hidden" name="state" value="{{ $filterState }}">
            @endif
            <button type="submit" onclick="return confirm('Uzavřít všechny nové záznamy (dle aktuálního filtru)?')">
                Uzavřít vše
            </button>
        </form>
    @endif

    <table class="extended" cellspacing="0">
        <thead>
            <tr>
                <th>ID</th>
                <th>Typ</th>
                <th>Stav</th>
                <th>Zaznamenáno</th>
                <th>Popis</th>
                <th>Uzavřeno</th>
                <th>Uzavřel</th>
                <th>Akce</th>
            </tr>
        </thead>
        <tbody>
            @forelse($logs as $log)
                <tr>
                    <td>{{ $log->id }}</td>
                    <td>
                        <b style="color:white; padding:2px 5px; border-radius:3px; background-color:{{ $typeColors[$log->type] ?? '#888' }}">
                            {{ $typeNames[$log->type] ?? $log->type }}
                        </b>
                    </td>
                    <td>
                        @if($log->state == 0)
                            <b style="color:#c00">Nový</b>
                        @else
                            Uzavřený
                        @endif
                    </td>
                    <td>{{ $log->created_at }}</td>
                    <td style="max-width:400px; word-break:break-word">
                        {{ \Illuminate\Support\Str::limit($log->description, 100) }}
                    </td>
                    <td>{{ $log->closed_at ?? '—' }}</td>
                    <td>{{ $log->closed_by_name ?? '—' }}</td>
                    <td>
                        <a href="{{ route('log_queues.show', $log->id) }}">Zobrazit</a>
                        @if($canEdit && $log->state == 0)
                            |
                            <form method="POST" action="{{ route('log_queues.close', $log->id) }}" style="display:inline">
                                @csrf
                                <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;color:#00c;text-decoration:underline"
                                        onclick="return confirm('Uzavřít tento záznam?')">Uzavřít</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" style="text-align:center">Žádné záznamy.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div style="margin-top:10px">
        {{ $logs->links() }}
    </div>
@endsection
