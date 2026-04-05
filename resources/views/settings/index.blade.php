@extends('layouts.app')

@section('title', 'Nastavení')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        Nastavení
    </div>
@endsection

@section('content')
    <h2>Nastavení</h2>

    <form method="POST" action="{{ route('settings.update') }}">
        @csrf
        @method('PUT')

        <h3>Přiřazení bankovních účtů k typům členů (import výpisů)</h3>

        <p>
            Platby přicházející na nesprávný bankovní účet pro daný typ člena
            zůstanou nespárované (neidentifikované). Toto odpovídá logice
            <code>pvfree_filter_member_by_bank_account</code> z původního systému,
            kde byly ID účtů 6160 (zákazník) a 10765 (člen) zakódována natvrdo.
        </p>

        <table class="extended" cellspacing="0">
            <thead>
                <tr>
                    <th>Typ člena</th>
                    <th>Bankovní účet pro příjem plateb</th>
                </tr>
            </thead>
            <tbody>
                @foreach($routing as $type => $rule)
                    <tr>
                        <th>{{ $rule['label'] }}</th>
                        <td>
                            <select name="routing_{{ $type }}">
                                <option value="0">(bez omezení)</option>
                                @foreach($bankAccounts as $ba)
                                    <option value="{{ $ba->id }}"
                                        {{ $rule['bank_account_id'] == $ba->id ? 'selected' : '' }}>
                                        {{ $ba->name }} ({{ $ba->full_account_number }})
                                    </option>
                                @endforeach
                            </select>
                        </td>
                    </tr>
                @endforeach
                <tr>
                    <th>Výchozí účet pro import<br><small>(použit když typ nemá pravidlo)</small></th>
                    <td>
                        <select name="default_bank_account_id">
                            <option value="0">(neurčen)</option>
                            @foreach($bankAccounts as $ba)
                                <option value="{{ $ba->id }}"
                                    {{ $defaultBaId == $ba->id ? 'selected' : '' }}>
                                    {{ $ba->name }} ({{ $ba->full_account_number }})
                                </option>
                            @endforeach
                        </select>
                    </td>
                </tr>
            </tbody>
        </table>

        <div style="margin-top:1em;">
            <button type="submit">Uložit nastavení</button>
        </div>
    </form>
@endsection
