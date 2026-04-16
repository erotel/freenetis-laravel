@extends('layouts.app')
@section('title', 'Šablona: ' . $template->name)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('device_templates.index') }}">Šablony zařízení</a> &raquo;
    {{ $template->name }}
</div>
@endsection
@section('content')
<h2>{{ $template->name }}</h2>

<div style="margin-bottom:1em;">
    @if($canEdit)
    <a href="{{ route('device_templates.edit', $template->id) }}">
        <img src="{{ asset('media/images/icons/gtk_edit.png') }}" alt=""> Upravit
    </a>
    &nbsp;|&nbsp;
    @endif
    @if($canDelete)
    <form method="POST" action="{{ route('device_templates.destroy', $template->id) }}" style="display:inline"
          onsubmit="return confirm('Smazat šablonu {{ addslashes($template->name) }}?')">
        @csrf @method('DELETE')
        <button type="submit" style="background:none;border:none;cursor:pointer;color:#c00;">
            <img src="{{ asset('media/images/icons/delete.png') }}" alt=""> Smazat
        </button>
    </form>
    @endif
</div>

<table class="extended" cellspacing="0">
    <thead><tr><th colspan="2">Základní informace</th></tr></thead>
    <tbody>
        <tr><th>ID</th><td>{{ $template->id }}</td></tr>
        <tr><th>Název</th><td>{{ $template->name }}</td></tr>
        <tr><th>Typ zařízení</th><td>{{ $template->enumType?->value ?? '—' }}</td></tr>
        <tr><th>Výchozí pro typ</th><td>{{ $template->default ? 'Ano' : 'Ne' }}</td></tr>
    </tbody>
</table>

@if(count($ifaceDefs) > 0)
<h3>Rozhraní</h3>
<table class="extended" cellspacing="0">
    <thead>
        <tr>
            <th>Typ</th>
            <th>Počet</th>
            <th>MAC</th>
            <th>IP</th>
            <th>Pojmenovaná rozhraní</th>
        </tr>
    </thead>
    <tbody>
        @foreach($ifaceDefs as $def)
        @if($def['count'] > 0 || $def['max_count'] > 0)
        <tr>
            <td>{{ $def['type_label'] }}</td>
            <td>
                @if($def['min_count'] !== $def['max_count'])
                    {{ $def['min_count'] }}–{{ $def['max_count'] }}
                @else
                    {{ $def['count'] }}
                @endif
            </td>
            <td>{{ $def['has_mac'] ? '✓' : '—' }}</td>
            <td>{{ $def['has_ip'] ? '✓' : '—' }}</td>
            <td>
                @if(count($def['items']) > 0)
                    {{ implode(', ', array_column($def['items'], 'name')) }}
                @else —
                @endif
            </td>
        </tr>
        @endif
        @endforeach
    </tbody>
</table>
@endif
@endsection
