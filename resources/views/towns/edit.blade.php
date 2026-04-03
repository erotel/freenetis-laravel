@extends('layouts.app')

@section('title', 'Upravit město')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('towns.index') }}">Města</a> &raquo;
        Upravit město
    </div>
@endsection

@section('content')
    <h2>Upravit město</h2>

    <form method="POST" action="{{ route('towns.update', $town->id) }}" class="form">
        @csrf
        @method('PUT')

        <table class="extended" cellspacing="0">
            <tbody>
                <tr>
                    <th><label for="town">Město <span class="required">*</span></label></th>
                    <td>
                        <input type="text" id="town" name="town" value="{{ old('town', $town->town) }}" maxlength="50">
                        @error('town')
                            <span class="error">{{ $message }}</span>
                        @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="quarter">Čtvrť</label></th>
                    <td>
                        <input type="text" id="quarter" name="quarter" value="{{ old('quarter', $town->quarter) }}" maxlength="50">
                        @error('quarter')
                            <span class="error">{{ $message }}</span>
                        @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="zip_code">PSČ <span class="required">*</span></label></th>
                    <td>
                        <input type="text" id="zip_code" name="zip_code" value="{{ old('zip_code', $town->zip_code) }}" maxlength="5" size="5">
                        @error('zip_code')
                            <span class="error">{{ $message }}</span>
                        @enderror
                    </td>
                </tr>
            </tbody>
        </table>

        <p>
            <input type="submit" value="Uložit">
        </p>
    </form>
@endsection
