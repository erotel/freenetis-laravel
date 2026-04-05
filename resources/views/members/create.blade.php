@extends('layouts.app')

@section('title', 'Přidat člena')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('members.index') }}">Členové</a> &raquo;
        Přidat nového člena
    </div>
@endsection

@section('content')
    <h2>Přidat nového člena</h2>

    <form method="POST" action="{{ route('members.store') }}" class="form">
        @csrf

        <table class="extended" cellspacing="0">
            <thead>
                <tr><th colspan="2">Základní informace</th></tr>
            </thead>
            <tbody>
                <tr>
                    <th><label for="name">Název / Jméno <span class="required">*</span></label></th>
                    <td>
                        <input type="text" id="name" name="name" value="{{ old('name') }}" maxlength="100">
                        @error('name') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="type">Typ člena <span class="required">*</span></label></th>
                    <td>
                        <select id="type" name="type">
                            @foreach($types as $value => $label)
                                <option value="{{ $value }}" @selected(old('type', 2) == $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('type') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="entrance_date">Datum vstupu</label></th>
                    <td>
                        <input type="date" id="entrance_date" name="entrance_date" value="{{ old('entrance_date', date('Y-m-d')) }}">
                        @error('entrance_date') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="organization_identifier">IČO</label></th>
                    <td>
                        <input type="text" id="organization_identifier" name="organization_identifier"
                               value="{{ old('organization_identifier') }}" maxlength="20">
                        @error('organization_identifier') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="vat_organization_identifier">DIČ</label></th>
                    <td>
                        <input type="text" id="vat_organization_identifier" name="vat_organization_identifier"
                               value="{{ old('vat_organization_identifier') }}" maxlength="30">
                        @error('vat_organization_identifier') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="comment">Poznámka</label></th>
                    <td>
                        <textarea id="comment" name="comment" maxlength="250">{{ old('comment') }}</textarea>
                        @error('comment') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr><th colspan="2" style="background:#e8e8e8;">Adresa</th></tr>
                <tr>
                    <th><label for="town_id">Město</label></th>
                    <td>
                        <select id="town_id" name="town_id">
                            <option value="">— vyberte město —</option>
                            @foreach($towns as $town)
                                <option value="{{ $town->id }}" @selected(old('town_id') == $town->id)>
                                    {{ $town->town }}{{ $town->quarter ? ' - ' . $town->quarter : '' }}, {{ $town->zip_code }}
                                </option>
                            @endforeach
                        </select>
                        @error('town_id') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="street_id">Ulice</label></th>
                    <td>
                        <select id="street_id" name="street_id">
                            <option value="">— vyberte ulici —</option>
                            @foreach($streets as $street)
                                <option value="{{ $street->id }}" @selected(old('street_id') == $street->id)>
                                    {{ $street->street }}
                                </option>
                            @endforeach
                        </select>
                        @error('street_id') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="street_number">Číslo popisné</label></th>
                    <td>
                        <input type="text" id="street_number" name="street_number"
                               value="{{ old('street_number') }}" maxlength="50">
                        @error('street_number') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr><th colspan="2" style="background:#e8e8e8;">Další informace</th></tr>
                <tr>
                    <th><label for="locked">Přístup do systému</label></th>
                    <td>
                        <select id="locked" name="locked">
                            <option value="0" @selected(!old('locked'))>Odemčen</option>
                            <option value="1" @selected(old('locked'))>Zamčen</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="registration">Registrace</label></th>
                    <td>
                        <select id="registration" name="registration">
                            <option value="1" @selected(old('registration', true))>Ano</option>
                            <option value="0" @selected(!old('registration', true))>Ne</option>
                        </select>
                    </td>
                </tr>
            </tbody>
        </table>

        <p><input type="submit" value="Přidat"></p>
    </form>
@endsection
