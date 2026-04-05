@extends('layouts.app')

@section('title', 'Výpisy: ' . $account->name)

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('bank_accounts.index') }}">Bankovní účty</a> &raquo;
        <a href="{{ route('bank_accounts.show', $account->id) }}">{{ $account->name }}</a> &raquo;
        Výpisy
    </div>
@endsection

@section('content')
    <h2>Bankovní výpisy: {{ $account->name }}</h2>

    <div style="margin-bottom:1em;">
        <a href="{{ route('bank_accounts.show', $account->id) }}">&larr; Zpět na účet</a>
    </div>

    <table class="extended" cellspacing="0">
        <thead>
            <tr>
                <th>ID</th>
                <th>Typ</th>
                <th>Od</th>
                <th>Do</th>
                <th style="text-align:right;">Počáteční zůstatek</th>
                <th style="text-align:right;">Konečný zůstatek</th>
                <th>Nahrál</th>
                <th>Akce</th>
            </tr>
        </thead>
        <tbody>
            @forelse($statements as $stmt)
                <tr>
                    <td>{{ $stmt->id }}</td>
                    <td>{{ $stmt->type ?: '—' }}</td>
                    <td>{{ $stmt->from?->format('d.m.Y') ?? '—' }}</td>
                    <td>{{ $stmt->to?->format('d.m.Y') ?? '—' }}</td>
                    <td style="text-align:right;">{{ number_format($stmt->opening_balance, 2, ',', ' ') }} Kč</td>
                    <td style="text-align:right;">{{ number_format($stmt->closing_balance, 2, ',', ' ') }} Kč</td>
                    <td>{{ $stmt->user?->name }} {{ $stmt->user?->surname }}</td>
                    <td class="action">
                        <form method="POST" action="{{ route('bank_statements.destroy', $stmt->id) }}"
                              style="display:inline;"
                              onsubmit="return confirm('Smazat výpis #{{ $stmt->id }} včetně všech převodů?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" title="Smazat"
                                    style="background:none;border:none;cursor:pointer;color:#c00;padding:0;">
                                Smazat
                            </button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8">Žádné výpisy.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="pagination-wrap">{{ $statements->links() }}</div>
@endsection
