@extends('layouts.app')

@section('title', 'Přidat novou ulici')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('streets.index') }}">Ulice</a> &raquo;
        Přidat novou ulici
    </div>
@endsection

@section('content')
    <h2>Přidat novou ulici</h2>

    <form method="POST" action="{{ route('streets.store') }}" class="form">
        @csrf

        <table class="extended" cellspacing="0">
            <tbody>
                <tr>
                    <th><label for="town_id">Město <span class="required">*</span></label></th>
                    <td>
                        <select id="town_id" name="town_id">
                            <option value="">-- vyberte město --</option>
                            @foreach($towns as $town)
                                <option value="{{ $town->id }}" @selected(old('town_id') == $town->id)>
                                    {{ $town->town }}{{ $town->quarter ? ' - ' . $town->quarter : '' }}, {{ $town->zip_code }}
                                </option>
                            @endforeach
                        </select>
                        @error('town_id')
                            <span class="error">{{ $message }}</span>
                        @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="street">Ulice <span class="required">*</span></label></th>
                    <td>
                        <input type="text" id="street" name="street" value="{{ old('street') }}" maxlength="50">
                        @error('street')
                            <span class="error">{{ $message }}</span>
                        @enderror
                    </td>
                </tr>
            </tbody>
        </table>

        <p>
            <input type="submit" value="Přidat">
        </p>
    </form>
@endsection
