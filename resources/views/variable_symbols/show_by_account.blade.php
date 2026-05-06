@extends('layouts.app')
@section('title', 'Variabilní symboly: ' . $account->name)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('members.index') }}">Členové</a> &raquo;
    @if($account->member)
        <a href="{{ route('members.show', $account->member_id) }}">{{ $account->member->name }}</a> &raquo;
    @endif
    <a href="{{ route('accounts.show', $account->id) }}">{{ $account->name }}</a> &raquo;
    Variabilní symboly
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Variabilní symboly: {{ $account->name }}</h2></div>

<div class="m-actions">
    <a class="m-btn" href="{{ route('accounts.show', $account->id) }}">&larr; Zpět na účet</a>
</div>

@if($canAdd)
<div class="m-card" style="max-width:420px;margin-bottom:16px">
    <div class="m-card-title">Přidat variabilní symbol</div>
    @if($errors->any())
    <div class="m-alert m-alert-danger" style="margin-bottom:12px">
        <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
    @endif
    <form method="POST" action="{{ route('variable_symbols.store', $account->id) }}" style="display:flex;gap:8px">
        @csrf
        <input class="m-form-input" type="text" id="variable_symbol" name="variable_symbol"
               value="{{ old('variable_symbol') }}" maxlength="10"
               placeholder="např. 103810" style="font-family:monospace;max-width:160px">
        <button class="m-btn m-btn-success" type="submit">+ Přidat</button>
    </form>
</div>
@endif

<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px">ID</th>
            <th>Variabilní symbol</th>
            <th style="width:70px">Akce</th>
        </tr>
    </thead>
    <tbody>
        @forelse($symbols as $symbol)
        <tr>
            <td>{{ $symbol->id }}</td>
            <td style="font-family:monospace">{{ $symbol->variable_symbol }}</td>
            <td>
                @if($canDelete)
                <form method="POST" action="{{ route('variable_symbols.destroy', $symbol->id) }}" style="display:inline"
                      onsubmit="return confirm('Opravdu smazat variabilní symbol {{ $symbol->variable_symbol }}?')">
                    @csrf @method('DELETE')
                    <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;font-size:14px;color:#c0392b">Smazat</button>
                </form>
                @endif
            </td>
        </tr>
        @empty
        <tr><td colspan="3" style="text-align:center;color:#aaa;padding:2rem">Žádné variabilní symboly.</td></tr>
        @endforelse
    </tbody>
</table>
</div>
</div>
@endsection
