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
<h2>{{ $template ? 'Upravit šablonu: ' . $template->name : 'Přidat šablonu' }}</h2>

@if($errors->any())
<div style="background:#f8d7da; border:1px solid #f5c6cb; padding:10px; margin-bottom:1em; border-radius:4px;">
    <ul style="margin:0; padding-left:1.2em;">
        @foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach
    </ul>
</div>
@endif

@php
    $vals = $template?->values ?? [];
    $defs = $template ? $template->getIfaceDefinitions() : [];
    $defsMap = collect($defs)->keyBy('type')->toArray();
@endphp

<form method="POST" action="{{ $template ? route('device_templates.update', $template->id) : route('device_templates.store') }}">
    @csrf
    @if($template) @method('PUT') @endif

    <h3>Základní informace</h3>
    <table class="extended" cellspacing="0">
        <tr>
            <th><label for="name">Název <span style="color:red">*</span></label></th>
            <td>
                <input type="text" id="name" name="name"
                       value="{{ old('name', $template?->name) }}" maxlength="80" style="width:300px">
                @error('name') <span class="error">{{ $message }}</span> @enderror
            </td>
        </tr>
        <tr>
            <th><label for="enum_type_id">Typ zařízení <span style="color:red">*</span></label></th>
            <td>
                <select id="enum_type_id" name="enum_type_id" style="width:220px">
                    <option value="">— vyberte typ —</option>
                    @foreach($deviceTypes as $id => $label)
                    <option value="{{ $id }}" @selected(old('enum_type_id', $template?->enum_type_id) == $id)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('enum_type_id') <span class="error">{{ $message }}</span> @enderror
            </td>
        </tr>
        <tr>
            <th>Výchozí pro typ</th>
            <td>
                <label>
                    <input type="checkbox" name="default" value="1" @checked(old('default', $template?->default))>
                    Výchozí šablona pro tento typ zařízení
                </label>
            </td>
        </tr>
    </table>

    {{-- Iface sections --}}
    @foreach($ifaceTypes as $type => $label)
    @php
        $prefix = "iface_{$type}";
        $def = $defsMap[$type] ?? null;
        $isWireless = ($type === \App\Models\Iface::WIRELESS);
    @endphp
    <h3>{{ $label }}</h3>
    <table class="extended" cellspacing="0">
        @if($isWireless)
        <tr>
            <th>Minimální počet</th>
            <td><input type="number" name="{{ $prefix }}_min_count"
                       value="{{ old("{$prefix}_min_count", $def['min_count'] ?? 0) }}"
                       min="0" max="99" style="width:60px"></td>
        </tr>
        <tr>
            <th>Maximální počet</th>
            <td>
                <input type="number" name="{{ $prefix }}_max_count" id="{{ $prefix }}_max_count"
                       value="{{ old("{$prefix}_max_count", $def['max_count'] ?? 0) }}"
                       min="0" max="99" style="width:60px"
                       onchange="updateNames('{{ $prefix }}', this.value)">
            </td>
        </tr>
        @else
        <tr>
            <th>Počet</th>
            <td>
                <input type="number" name="{{ $prefix }}_count" id="{{ $prefix }}_count"
                       value="{{ old("{$prefix}_count", $def['count'] ?? 0) }}"
                       min="0" max="99" style="width:60px"
                       onchange="updateNames('{{ $prefix }}', this.value)">
            </td>
        </tr>
        @endif
        <tr>
            <th>Pojmenování rozhraní</th>
            <td>
                <div id="{{ $prefix }}_names_container">
                    @php
                        $existingItems = $def['items'] ?? [];
                        $existingCount = count($existingItems);
                        $totalCount = $isWireless ? ($def['max_count'] ?? 0) : ($def['count'] ?? 0);
                    @endphp
                    @for($i = 0; $i < $totalCount; $i++)
                    <div style="margin-bottom:2px;">
                        <small style="color:#888;">{{ $i + 1 }}.</small>
                        <input type="text" name="{{ $prefix }}_names[]"
                               value="{{ old("{$prefix}_names.{$i}", $existingItems[$i]['name'] ?? '') }}"
                               style="width:150px" placeholder="např. eth{{ $i + 1 }}">
                    </div>
                    @endfor
                </div>
            </td>
        </tr>
    </table>
    @endforeach

    <div style="margin-top:1em;">
        <button type="submit">{{ $template ? 'Uložit změny' : 'Přidat šablonu' }}</button>
        &nbsp;
        <a href="{{ $template ? route('device_templates.show', $template->id) : route('device_templates.index') }}">Zrušit</a>
    </div>
</form>

<script>
function updateNames(prefix, count) {
    count = parseInt(count) || 0;
    var container = document.getElementById(prefix + '_names_container');
    var existing = container.querySelectorAll('div');
    var currentCount = existing.length;

    // Add missing inputs
    for (var i = currentCount; i < count; i++) {
        var div = document.createElement('div');
        div.style.marginBottom = '2px';
        div.innerHTML = '<small style="color:#888;">' + (i + 1) + '.</small> ' +
            '<input type="text" name="' + prefix + '_names[]" style="width:150px" placeholder="např. iface' + (i + 1) + '">';
        container.appendChild(div);
    }
    // Remove extra inputs
    for (var i = currentCount - 1; i >= count; i--) {
        container.removeChild(existing[i]);
    }
}
</script>
@endsection
