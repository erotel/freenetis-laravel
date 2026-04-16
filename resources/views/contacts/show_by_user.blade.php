@extends('layouts.app')
@section('title', 'Kontakty: ' . $user->full_name)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('users.index') }}">Uživatelé</a> &raquo;
    <a href="{{ route('users.show', $user->id) }}">{{ $user->full_name }}</a> &raquo;
    Kontakty
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Kontakty uživatele {{ $user->full_name }}</h2></div>

@if($canAdd)
<div class="m-actions">
    <a class="m-btn m-btn-success" href="{{ route('contacts.create', $user->id) }}">+ Přidat kontakt</a>
</div>
@endif

@if($contacts->isEmpty())
<div class="m-card">
    <div style="text-align:center;color:#aaa;padding:2rem">Žádné kontakty.</div>
</div>
@else
<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px">ID</th>
            <th style="width:160px">Typ</th>
            <th>Hodnota</th>
            <th style="width:100px">Akce</th>
        </tr>
    </thead>
    <tbody>
        @foreach($contacts as $contact)
        <tr>
            <td>{{ $contact->id }}</td>
            <td>{{ $contact->enumType?->value ?? $contact->type }}</td>
            <td>{{ $contact->value }}</td>
            <td>
                <div style="display:flex;gap:6px">
                    @if($canEdit)
                    <a class="m-link-sm" href="{{ route('contacts.edit', [$user->id, $contact->id]) }}">Upravit</a>
                    @endif
                    @if($canDelete)
                    <form method="POST" action="{{ route('contacts.destroy', [$user->id, $contact->id]) }}" style="display:inline"
                          onsubmit="return confirm('Opravdu smazat kontakt {{ addslashes($contact->value) }}?')">
                        @csrf @method('DELETE')
                        <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;font-size:12px;color:#c0392b">Smazat</button>
                    </form>
                    @endif
                </div>
            </td>
        </tr>
        @endforeach
    </tbody>
</table>
</div>
@endif
</div>
@endsection
