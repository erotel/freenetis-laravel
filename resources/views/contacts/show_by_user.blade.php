@extends('layouts.app')

@section('title', 'Kontakty: ' . $user->full_name)

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('users.index') }}">Uživatelé</a> &raquo;
        <a href="{{ route('users.show', $user->id) }}">{{ $user->full_name }}</a> &raquo;
        Kontakty
    </div>
@endsection

@section('content')
    <h2>Kontakty uživatele {{ $user->full_name }}</h2>

    @if($canAdd)
        <p>
            <a href="{{ route('contacts.create', $user->id) }}">
                <img src="{{ asset('media/images/icons/ico_add.gif') }}" alt="Přidat">
                Přidat kontakt
            </a>
        </p>
    @endif

    @if($contacts->isEmpty())
        <p>Žádné kontakty.</p>
    @else
        <table class="extended" cellspacing="0">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Typ</th>
                    <th>Hodnota</th>
                    <th>Akce</th>
                </tr>
            </thead>
            <tbody>
                @foreach($contacts as $contact)
                    <tr>
                        <td>{{ $contact->id }}</td>
                        <td>{{ $contact->enumType?->value ?? $contact->type }}</td>
                        <td>{{ $contact->value }}</td>
                        <td>
                            @if($canEdit)
                                <a href="{{ route('contacts.edit', [$user->id, $contact->id]) }}" title="Upravit">
                                    <img src="{{ asset('media/images/icons/gtk_edit.png') }}" alt="Upravit">
                                </a>
                            @endif
                            @if($canDelete)
                                <form method="POST"
                                      action="{{ route('contacts.destroy', [$user->id, $contact->id]) }}"
                                      style="display:inline;"
                                      onsubmit="return confirm('Opravdu smazat kontakt {{ addslashes($contact->value) }}?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="icon-button" title="Smazat">
                                        <img src="{{ asset('media/images/icons/delete.png') }}" alt="Smazat">
                                    </button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
