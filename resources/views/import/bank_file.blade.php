@extends('layouts.app')

@section('title', 'Import bankovního výpisu: ' . $account->name)

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('bank_accounts.index') }}">Bankovní účty</a> &raquo;
        <a href="{{ route('bank_accounts.show', $account->id) }}">{{ $account->name }}</a> &raquo;
        Import výpisu
    </div>
@endsection

@section('content')
    <h2>Import bankovního výpisu: {{ $account->name }}</h2>

    <p>Nahrajte výpis ve formátu FIO CSV (kódování UTF-8, oddělovač středník).</p>

    <form method="POST" action="{{ route('import.bank_file', $account->id) }}"
          enctype="multipart/form-data">
        @csrf

        <table class="extended" cellspacing="0">
            <tbody>
                <tr>
                    <th>Soubor CSV</th>
                    <td>
                        <input type="file" name="csv_file" accept=".csv,.txt" required>
                        @error('csv_file')
                            <span style="color:#c00;"> {{ $message }}</span>
                        @enderror
                    </td>
                </tr>
            </tbody>
        </table>

        <div style="margin-top:1em;">
            <button type="submit">Importovat</button>
            &nbsp;
            <a href="{{ route('bank_accounts.show', $account->id) }}">Zpět</a>
        </div>
    </form>
@endsection
