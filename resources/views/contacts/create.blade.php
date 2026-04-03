@extends('layouts.app')

@section('title', 'Přidat kontakt')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('users.index') }}">Uživatelé</a> &raquo;
        <a href="{{ route('users.show', $user->id) }}">{{ $user->full_name }}</a> &raquo;
        <a href="{{ route('contacts.show_by_user', $user->id) }}">Kontakty</a> &raquo;
        Přidat
    </div>
@endsection

@section('content')
    <h2>Přidat kontakt — {{ $user->full_name }}</h2>

    <form method="POST" action="{{ route('contacts.store', $user->id) }}" class="form">
        @csrf

        <table class="extended" cellspacing="0">
            <tbody>
                <tr>
                    <th><label for="type">Typ <span class="required">*</span></label></th>
                    <td>
                        <select id="type" name="type">
                            <option value="">— vyberte typ —</option>
                            @foreach($contactTypes as $id => $label)
                                <option value="{{ $id }}" @selected(old('type') == $id)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('type') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="value">Hodnota <span class="required">*</span></label></th>
                    <td>
                        <input type="text" id="value" name="value" value="{{ old('value') }}" maxlength="255">
                        @error('value') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
            </tbody>
        </table>

        <p><input type="submit" value="Přidat"></p>
    </form>
@endsection
