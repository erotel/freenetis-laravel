@extends('layouts.app')
@section('title', 'Šablona: ' . $template->name)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('device_templates.index') }}">Šablony zařízení</a> &raquo; {{ $template->name }}
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>{{ $template->name }}</h2></div>

<div class="m-actions">
    @if($canEdit) <a class="m-btn" href="{{ route('device_templates.edit', $template->id) }}">Upravit</a> @endif
    @if($canDelete)
    <form method="POST" action="{{ route('device_templates.destroy', $template->id) }}" style="display:inline"
          onsubmit="return confirm('Smazat šablonu {{ addslashes($template->name) }}?')">
        @csrf @method('DELETE')
        <button class="m-btn m-btn-danger" type="submit">Smazat</button>
    </form>
    @endif
</div>

<div class="m-grid2">
    <div class="m-card">
        <div class="m-card-title">Základní informace</div>
        <div class="m-field"><span class="m-field-label">ID</span><span class="m-field-value">{{ $template->id }}</span></div>
        <div class="m-field"><span class="m-field-label">Název</span><span class="m-field-value">{{ $template->name }}</span></div>
        <div class="m-field"><span class="m-field-label">Typ zařízení</span><span class="m-field-value">{{ $template->enumType?->value ?? '—' }}</span></div>
        <div class="m-field">
            <span class="m-field-label">Výchozí pro typ</span>
            <span class="m-field-value">@if($template->default)<span class="m-tag m-tag-green">Ano</span>@else Ne @endif</span>
        </div>
    </div>
    <div></div>
</div>

@if(count($ifaceDefs) > 0)
<div class="m-section">Rozhraní</div>
<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th>Typ</th>
            <th style="width:80px">Počet</th>
            <th style="width:60px">MAC</th>
            <th style="width:60px">IP</th>
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
            <td>@if($def['has_mac'])<span class="m-tag m-tag-green">Ano</span>@else —@endif</td>
            <td>@if($def['has_ip'])<span class="m-tag m-tag-green">Ano</span>@else —@endif</td>
            <td style="font-size:14px">
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
</div>
@endif
</div>
@endsection
