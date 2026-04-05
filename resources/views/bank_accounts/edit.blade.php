@extends('layouts.app')

@section('title', 'Upravit bankovní účet: ' . $account->name)

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('bank_accounts.index') }}">Bankovní účty</a> &raquo;
        <a href="{{ route('bank_accounts.show', $account->id) }}">{{ $account->name }}</a> &raquo;
        Upravit
    </div>
@endsection

@section('content')
    <h2>Upravit bankovní účet: {{ $account->name }}</h2>

    <form method="POST" action="{{ route('bank_accounts.update', $account->id) }}">
        @csrf
        @method('PUT')

        <table class="extended" cellspacing="0">
            <tbody>
                <tr>
                    <th>IBAN</th>
                    <td>
                        <input type="text" name="IBAN" value="{{ old('IBAN', $account->IBAN) }}"
                               maxlength="34" style="width:300px;">
                        @error('IBAN') <span style="color:#c00;"> {{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th>SWIFT</th>
                    <td>
                        <input type="text" name="SWIFT" value="{{ old('SWIFT', $account->SWIFT) }}"
                               maxlength="11" style="width:150px;">
                        @error('SWIFT') <span style="color:#c00;"> {{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th>FIO API token</th>
                    <td>
                        <input type="text" name="fio_token" value="{{ old('fio_token', $fioToken) }}"
                               maxlength="200" style="width:400px;"
                               placeholder="Vložte token z FIO internet bankingu">
                        @error('fio_token') <span style="color:#c00;"> {{ $message }}</span> @enderror
                        <br>
                        <small>Token najdete v FIO Internet Bankingu → Nastavení → API</small>
                    </td>
                </tr>
            </tbody>
        </table>

        <div style="margin-top:1em;">
            <button type="submit">Uložit</button>
            &nbsp;
            <a href="{{ route('bank_accounts.show', $account->id) }}">Zpět</a>
        </div>
    </form>
@endsection
