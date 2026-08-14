@extends('layouts.app')
@section('title', 'Vrácení neidentifikované platby')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('bank_transfers.unidentified') }}">Neidentifikované převody</a> &raquo; Vrácení platby
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Vrácení neidentifikované platby</h2></div>
<div class="m-subtitle">Vytvoří odchozí platbu (koncept) pro vrácení prostředků původnímu odesílateli.</div>

<div class="m-card" style="max-width:480px;margin-bottom:16px">
    <div class="m-card-title">Informace o platbě</div>
    <div class="m-field">
        <span class="m-field-label">Bankovní účet</span>
        <span class="m-field-value">{{ $bankAccount->name }} ({{ $bankAccount->full_account_number }})</span>
    </div>
    <div class="m-field">
        <span class="m-field-label">Příjemce (protiúčet)</span>
        <span class="m-field-value">{{ $prefill['target_account'] }} — {{ $prefill['target_name'] }}</span>
    </div>
    <div class="m-field">
        <span class="m-field-label">Původní částka</span>
        <span class="m-field-value" style="font-family:monospace">{{ number_format($prefill['amount'], 2, ',', ' ') }} CZK</span>
    </div>
    <div class="m-field">
        <span class="m-field-label">Komentář</span>
        <span class="m-field-value">{{ $bt->comment }}</span>
    </div>
</div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

<form method="POST" action="{{ route('bank-transfers.refund.store', $bt->id) }}">
@csrf
<input type="hidden" name="bank_account_id" value="{{ $prefill['bank_account_id'] }}">
<input type="hidden" name="currency" value="CZK">

<div class="m-card" style="margin-bottom:16px;max-width:480px">
    <div class="m-form-group">
        <label class="m-form-label" for="target_account">Cílový účet</label>
        <input class="m-form-input" type="text" id="target_account"
               value="{{ $prefill['target_account'] }}" style="font-family:monospace;background:#f5f5f5" readonly>
        <div class="m-form-hint">Vrácení jde vždy zpět odesílateli — účet nelze měnit.</div>
    </div>
    <div class="m-form-group">
        <label class="m-form-label">Název příjemce</label>
        <input class="m-form-input" type="text"
               value="{{ $prefill['target_name'] }}" style="background:#f5f5f5" readonly>
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label">Částka (CZK)</label>
            <input class="m-form-input" type="text" name="amount"
                   value="{{ old('amount', number_format($prefill['amount'], 2, '.', '')) }}"
                   style="font-family:monospace;max-width:140px">
            <div class="m-form-hint">Max. {{ number_format($prefill['amount'], 2, ',', ' ') }} CZK (přijatá částka).</div>
            @error('amount') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label">Variabilní symbol</label>
            <input class="m-form-input" type="text" name="variable_symbol"
                   value="{{ old('variable_symbol', $prefill['variable_symbol']) }}"
                   style="font-family:monospace;max-width:160px">
        </div>
    </div>
    <div class="m-form-group">
        <label class="m-form-label">Zpráva</label>
        <input class="m-form-input" type="text" name="message"
               value="{{ old('message', $prefill['message']) }}">
    </div>
</div>

<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Vytvořit odchozí platbu (koncept)</button>
    <a class="m-btn" href="{{ route('bank_transfers.unidentified') }}">Zrušit</a>
</div>
</form>
</div>
@endsection
