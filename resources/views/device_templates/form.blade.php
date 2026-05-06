@extends('layouts.app')
@section('title', $template ? 'Upravit šablonu' : 'Přidat šablonu')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('device_templates.index') }}">Šablony zařízení</a> &raquo;
    {{ $template ? 'Upravit: ' . $template->name : 'Přidat šablonu' }}
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>{{ $template ? 'Upravit šablonu: ' . $template->name : 'Přidat šablonu' }}</h2></div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

@php
    $defs    = $template ? $template->getIfaceDefinitions() : [];
    $defsMap = collect($defs)->keyBy('type')->toArray();
@endphp

<form method="POST" action="{{ $template ? route('device_templates.update', $template->id) : route('device_templates.store') }}">
@csrf
@if($template) @method('PUT') @endif

<div class="m-card" style="margin-bottom:16px;max-width:480px">
    <div class="m-card-title">Základní informace</div>
    <div class="m-form-group">
        <label class="m-form-label" for="name">Název <span style="color:#c0392b">*</span></label>
        <input class="m-form-input" type="text" id="name" name="name"
               value="{{ old('name', $template?->name) }}" maxlength="80">
        @error('name') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="enum_type_id">Typ zařízení <span style="color:#c0392b">*</span></label>
            <select class="m-form-select" id="enum_type_id" name="enum_type_id">
                <option value="">— vyberte typ —</option>
                @foreach($deviceTypes as $id => $label)
                <option value="{{ $id }}" @selected(old('enum_type_id', $template?->enum_type_id) == $id)>{{ $label }}</option>
                @endforeach
            </select>
            @error('enum_type_id') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group" style="display:flex;align-items:flex-end;padding-bottom:2px">
            <label style="display:flex;align-items:center;gap:6px;font-size:16px;cursor:pointer">
                <input type="checkbox" name="default" value="1" @checked(old('default', $template?->default))>
                Výchozí šablona pro tento typ
            </label>
        </div>
    </div>
</div>

@foreach($ifaceTypes as $type => $label)
@php
    $prefix = "iface_{$type}";
    $def = $defsMap[$type] ?? null;
    $isWireless = ($type === \App\Models\Iface::WIRELESS);
@endphp
<div class="m-card" style="margin-bottom:16px;max-width:560px">
    <div class="m-card-title">{{ $label }}</div>
    @if($isWireless)
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label">Minimální počet</label>
            <input class="m-form-input" type="number" name="{{ $prefix }}_min_count"
                   value="{{ old("{$prefix}_min_count", $def['min_count'] ?? 0) }}"
                   min="0" max="99" style="max-width:80px">
        </div>
        <div class="m-form-group">
            <label class="m-form-label">Maximální počet</label>
            <input class="m-form-input" type="number" name="{{ $prefix }}_max_count" id="{{ $prefix }}_max_count"
                   value="{{ old("{$prefix}_max_count", $def['max_count'] ?? 0) }}"
                   min="0" max="99" style="max-width:80px"
                   onchange="updateNames('{{ $prefix }}', this.value)">
        </div>
    </div>
    @else
    <div class="m-form-group">
        <label class="m-form-label">Počet</label>
        <input class="m-form-input" type="number" name="{{ $prefix }}_count" id="{{ $prefix }}_count"
               value="{{ old("{$prefix}_count", $def['count'] ?? 0) }}"
               min="0" max="99" style="max-width:80px"
               onchange="updateNames('{{ $prefix }}', this.value)">
    </div>
    @endif
    <div class="m-form-group">
        <label class="m-form-label">Pojmenování rozhraní</label>
        <div id="{{ $prefix }}_names_container">
            @php
                $existingItems = $def['items'] ?? [];
                $totalCount = $isWireless ? ($def['max_count'] ?? 0) : ($def['count'] ?? 0);
            @endphp
            @for($i = 0; $i < $totalCount; $i++)
            <div style="display:flex;align-items:center;gap:6px;margin-bottom:4px">
                <span style="font-size:14px;color:#aaa;min-width:20px">{{ $i + 1 }}.</span>
                <input class="m-form-input" type="text" name="{{ $prefix }}_names[]"
                       value="{{ old("{$prefix}_names.{$i}", $existingItems[$i]['name'] ?? '') }}"
                       placeholder="např. eth{{ $i + 1 }}" style="max-width:180px">
            </div>
            @endfor
        </div>
    </div>
</div>
@endforeach

<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">{{ $template ? 'Uložit změny' : 'Přidat šablonu' }}</button>
    <a class="m-btn" href="{{ $template ? route('device_templates.show', $template->id) : route('device_templates.index') }}">Zrušit</a>
</div>
</form>

<script>
function updateNames(prefix, count) {
    count = parseInt(count) || 0;
    var container = document.getElementById(prefix + '_names_container');
    var existing = container.querySelectorAll('div');
    var currentCount = existing.length;
    for (var i = currentCount; i < count; i++) {
        var div = document.createElement('div');
        div.style.cssText = 'display:flex;align-items:center;gap:6px;margin-bottom:4px';
        div.innerHTML = '<span style="font-size:14px;color:#aaa;min-width:20px">' + (i + 1) + '.</span>' +
            '<input class="m-form-input" type="text" name="' + prefix + '_names[]" placeholder="např. iface' + (i + 1) + '" style="max-width:180px">';
        container.appendChild(div);
    }
    for (var i = currentCount - 1; i >= count; i--) {
        container.removeChild(existing[i]);
    }
}
</script>
</div>
@endsection
