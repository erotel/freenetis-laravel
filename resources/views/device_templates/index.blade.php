@extends('layouts.app')
@section('title', 'Šablony zařízení')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">Šablony zařízení</div>
@endsection
@section('content')
<h2>Šablony zařízení</h2>

<div style="margin-bottom:1em;">
    @if($canNew)
    <a href="{{ route('device_templates.create') }}">
        <img src="{{ asset('media/images/icons/ico_add.gif') }}" alt=""> Přidat šablonu
    </a>
    &nbsp;|&nbsp;
    @endif
    <a href="{{ route('device_templates.export') }}">Exportovat JSON</a>
</div>

<table class="extended" cellspacing="0">
    <thead>
        <tr>
            <th>ID</th>
            <th>Název</th>
            <th>Typ zařízení</th>
            <th>Výchozí</th>
            <th>Akce</th>
        </tr>
    </thead>
    <tbody>
        @forelse($templates as $t)
        <tr>
            <td>{{ $t->id }}</td>
            <td><a href="{{ route('device_templates.show', $t->id) }}">{{ $t->name }}</a></td>
            <td>{{ $t->enumType?->value ?? '—' }}</td>
            <td>{{ $t->default ? '✓' : '—' }}</td>
            <td>
                <a href="{{ route('device_templates.show', $t->id) }}">
                    <img src="{{ asset('media/images/icons/con_info.png') }}" alt="Detail" title="Detail">
                </a>
                <a href="{{ route('device_templates.edit', $t->id) }}">
                    <img src="{{ asset('media/images/icons/gtk_edit.png') }}" alt="Upravit" title="Upravit">
                </a>
                <form method="POST" action="{{ route('device_templates.destroy', $t->id) }}" style="display:inline"
                      onsubmit="return confirm('Smazat šablonu {{ addslashes($t->name) }}?')">
                    @csrf @method('DELETE')
                    <button type="submit" style="background:none;border:none;padding:0;cursor:pointer;">
                        <img src="{{ asset('media/images/icons/delete.png') }}" alt="Smazat" title="Smazat">
                    </button>
                </form>
            </td>
        </tr>
        @empty
        <tr><td colspan="5" style="text-align:center;">Žádné šablony.</td></tr>
        @endforelse
    </tbody>
</table>
@endsection
