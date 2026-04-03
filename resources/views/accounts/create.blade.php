@extends('layouts.app')

@section('title', 'Přidat projektový účet')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('accounts.index', ['type' => 'project']) }}">Účty</a> &raquo;
        Přidat projektový účet
    </div>
@endsection

@section('content')
    <h2>Přidat projektový účet</h2>

    <p><em>Projektové účty slouží k evidenci specifických rozpočtových položek.</em></p>

    <form method="POST" action="{{ route('accounts.store') }}" class="form">
        @csrf

        <table class="extended" cellspacing="0">
            <tbody>
                <tr>
                    <th><label for="name">Název <span class="required">*</span></label></th>
                    <td>
                        <input type="text" id="name" name="name"
                               value="{{ old('name') }}" maxlength="100">
                        @error('name') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="comment">Komentář</label></th>
                    <td>
                        <textarea id="comment" name="comment" maxlength="254">{{ old('comment') }}</textarea>
                        @error('comment') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
            </tbody>
        </table>

        <p><input type="submit" value="Přidat"></p>
    </form>
@endsection
