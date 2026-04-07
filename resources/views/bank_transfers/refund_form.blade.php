@extends('layouts.app')

@section('title', 'Vrácení neidentifikované platby')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('bank_transfers.unidentified') }}">Neidentifikované převody</a> »
        Vrácení platby
    </div>
@endsection

@section('content')
    <h2>Vrácení neidentifikované platby</h2>

    <p>Vytvoří odchozí platbu (koncept) pro vrácení prostředků původnímu odesílateli.</p>

    <table class="extended" cellspacing="0">
        <tr>
            <th>Bankovní účet (odesílatel vrácení)</th>
            <td>{{ $bankAccount->name }} ({{ $bankAccount->full_account_number }})</td>
        </tr>
        <tr>
            <th>Příjemce (protiúčet)</th>
            <td>{{ $prefill['target_account'] }} — {{ $prefill['target_name'] }}</td>
        </tr>
        <tr>
            <th>Původní částka</th>
            <td>{{ number_format($prefill['amount'], 2, ',', ' ') }} CZK</td>
        </tr>
        <tr>
            <th>Komentář</th>
            <td>{{ $bt->comment }}</td>
        </tr>
    </table>

    <form method="POST" action="{{ route('bank-transfers.refund.store', $bt->id) }}" style="margin-top:1.5em;">
        @csrf

        <input type="hidden" name="bank_account_id" value="{{ $prefill['bank_account_id'] }}">
        <input type="hidden" name="currency" value="CZK">

        <table class="extended" cellspacing="0">
            <tr>
                <th>Cílový účet</th>
                <td>
                    <input type="text" name="target_account" value="{{ old('target_account', $prefill['target_account']) }}" style="width:200px;">
                    @error('target_account') <span style="color:red">{{ $message }}</span> @enderror
                </td>
            </tr>
            <tr>
                <th>Název příjemce</th>
                <td>
                    <input type="text" name="target_name" value="{{ old('target_name', $prefill['target_name']) }}" style="width:250px;">
                </td>
            </tr>
            <tr>
                <th>Částka (CZK)</th>
                <td>
                    <input type="text" name="amount" value="{{ old('amount', number_format($prefill['amount'], 2, '.', '')) }}" style="width:100px;">
                    @error('amount') <span style="color:red">{{ $message }}</span> @enderror
                </td>
            </tr>
            <tr>
                <th>Variabilní symbol</th>
                <td>
                    <input type="text" name="variable_symbol" value="{{ old('variable_symbol', $prefill['variable_symbol']) }}" style="width:150px;">
                </td>
            </tr>
            <tr>
                <th>Zpráva</th>
                <td>
                    <input type="text" name="message" value="{{ old('message', $prefill['message']) }}" style="width:350px;">
                </td>
            </tr>
        </table>

        <div style="margin-top:1em;">
            <button type="submit">Vytvořit odchozí platbu (koncept)</button>
            <a href="{{ route('bank_transfers.unidentified') }}" style="margin-left:1em;">Zrušit</a>
        </div>
    </form>
@endsection
