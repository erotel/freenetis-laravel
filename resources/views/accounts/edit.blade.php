@extends('layouts.app')

@section('title', 'Upravit účet: ' . $account->name)

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('accounts.index') }}">Účty</a> &raquo;
        <a href="{{ route('accounts.show', $account->id) }}">{{ $account->name }}</a> &raquo;
        Upravit
    </div>
@endsection

@section('content')
    <h2>Upravit účet: {{ $account->name }}</h2>

    <form method="POST" action="{{ route('accounts.update', $account->id) }}" class="form">
        @csrf
        @method('PUT')

        <table class="extended" cellspacing="0">
            <tbody>
                <tr>
                    <th>Typ účtu</th>
                    <td><strong>{{ $account->accountAttribute?->name ?? '—' }}</strong></td>
                </tr>
                <tr>
                    <th><label for="name">Název <span class="required">*</span></label></th>
                    <td>
                        <input type="text" id="name" name="name"
                               value="{{ old('name', $account->name) }}" maxlength="100">
                        @error('name') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="comment">Komentář</label></th>
                    <td>
                        <textarea id="comment" name="comment" maxlength="254">{{ old('comment', $account->comment) }}</textarea>
                        @error('comment') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
            </tbody>
        </table>

        <p><input type="submit" value="Uložit"></p>
    </form>
@endsection
