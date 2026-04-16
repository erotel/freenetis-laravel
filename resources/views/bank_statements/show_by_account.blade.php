@extends('layouts.app')
@section('title', 'Výpisy: ' . $account->name)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('bank_accounts.index') }}">Bankovní účty</a> &raquo;
    <a href="{{ route('bank_accounts.show', $account->id) }}">{{ $account->name }}</a> &raquo;
    Výpisy
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Bankovní výpisy: {{ $account->name }}</h2></div>

<div class="m-actions">
    <a class="m-btn" href="{{ route('bank_accounts.show', $account->id) }}">&larr; Zpět na účet</a>
</div>

<div style="margin-bottom:8px">{{ $statements->links() }}</div>

<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px">ID</th>
            <th style="width:90px">Typ</th>
            <th style="width:100px">Od</th>
            <th style="width:100px">Do</th>
            <th style="width:130px;text-align:right">Poč. zůstatek</th>
            <th style="width:130px;text-align:right">Kon. zůstatek</th>
            <th>Nahrál</th>
            <th style="width:70px">Akce</th>
        </tr>
    </thead>
    <tbody>
        @forelse($statements as $stmt)
        <tr>
            <td>{{ $stmt->id }}</td>
            <td style="font-size:12px">{{ $stmt->type ?: '—' }}</td>
            <td style="font-size:12px">{{ $stmt->from?->format('d.m.Y') ?? '—' }}</td>
            <td style="font-size:12px">{{ $stmt->to?->format('d.m.Y') ?? '—' }}</td>
            <td style="text-align:right;font-family:monospace;font-size:12px">{{ number_format($stmt->opening_balance, 2, ',', ' ') }} Kč</td>
            <td style="text-align:right;font-family:monospace;font-size:12px">{{ number_format($stmt->closing_balance, 2, ',', ' ') }} Kč</td>
            <td style="font-size:12px">{{ $stmt->user?->name }} {{ $stmt->user?->surname }}</td>
            <td>
                <form method="POST" action="{{ route('bank_statements.destroy', $stmt->id) }}" style="display:inline"
                      onsubmit="return confirm('Smazat výpis #{{ $stmt->id }} včetně všech převodů?')">
                    @csrf @method('DELETE')
                    <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;font-size:12px;color:#c0392b">Smazat</button>
                </form>
            </td>
        </tr>
        @empty
        <tr><td colspan="8" style="text-align:center;color:#aaa;padding:2rem">Žádné výpisy.</td></tr>
        @endforelse
    </tbody>
</table>
</div>

<div style="margin-top:12px">{{ $statements->links() }}</div>
</div>
@endsection
