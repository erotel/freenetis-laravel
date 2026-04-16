@extends('layouts.app')
@section('title', 'Šablony zařízení')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">Šablony zařízení</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Šablony zařízení</h2></div>

<div class="m-actions">
    @if($canNew) <a class="m-btn m-btn-success" href="{{ route('device_templates.create') }}">+ Přidat šablonu</a> @endif
    <a class="m-btn" href="{{ route('device_templates.export') }}">Exportovat JSON</a>
</div>

<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px">ID</th>
            <th>Název</th>
            <th style="width:150px">Typ zařízení</th>
            <th style="width:80px">Výchozí</th>
            <th style="width:100px">Akce</th>
        </tr>
    </thead>
    <tbody>
        @forelse($templates as $t)
        <tr>
            <td>{{ $t->id }}</td>
            <td><a class="m-link" href="{{ route('device_templates.show', $t->id) }}">{{ $t->name }}</a></td>
            <td>{{ $t->enumType?->value ?? '—' }}</td>
            <td>@if($t->default)<span class="m-tag m-tag-green">Ano</span>@else —@endif</td>
            <td>
                <div style="display:flex;gap:6px">
                    <a class="m-link-sm" href="{{ route('device_templates.show', $t->id) }}">Detail</a>
                    <a class="m-link-sm" href="{{ route('device_templates.edit', $t->id) }}">Upravit</a>
                    <form method="POST" action="{{ route('device_templates.destroy', $t->id) }}" style="display:inline"
                          onsubmit="return confirm('Smazat šablonu {{ addslashes($t->name) }}?')">
                        @csrf @method('DELETE')
                        <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;font-size:12px;color:#c0392b">Smazat</button>
                    </form>
                </div>
            </td>
        </tr>
        @empty
        <tr><td colspan="5" style="text-align:center;color:#aaa;padding:2rem">Žádné šablony.</td></tr>
        @endforelse
    </tbody>
</table>
</div>
</div>
@endsection
