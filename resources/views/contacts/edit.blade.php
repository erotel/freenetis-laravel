@extends('layouts.app')

@section('title', 'Upravit kontakt')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('users.index') }}">Uživatelé</a> &raquo;
        <a href="{{ route('users.show', $user->id) }}">{{ $user->full_name }}</a> &raquo;
        <a href="{{ route('contacts.show_by_user', $user->id) }}">Kontakty</a> &raquo;
        Upravit
    </div>
@endsection

@section('content')
    <h2>Upravit kontakt — {{ $user->full_name }}</h2>

    <form method="POST" action="{{ route('contacts.update', [$user->id, $contact->id]) }}" class="form">
        @csrf
        @method('PUT')

        <table class="extended" cellspacing="0">
            <tbody>
                <tr>
                    <th>Typ</th>
                    <td><strong>{{ $contact->enumType?->value ?? $contact->type }}</strong></td>
                </tr>
                <tr>
                    <th><label for="value">Hodnota <span class="required">*</span></label></th>
                    <td>
                        <input type="text" id="value" name="value"
                               value="{{ old('value', $contact->value) }}" maxlength="255">
                        @error('value') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
            </tbody>
        </table>

        <p><input type="submit" value="Uložit"></p>
    </form>
@endsection
