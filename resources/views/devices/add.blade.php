@extends('layouts.app')

@section('title', 'Přidat zařízení')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('devices.index') }}">Zařízení</a> &raquo;
        Přidat zařízení
    </div>
@endsection

@section('content')
    <h2>Přidat zařízení</h2>

    <form method="POST" action="{{ route('devices.store_template') }}" class="form" id="device-add-form">
        @csrf

        {{-- Section 1: Zařízení --}}
        <h3>Zařízení</h3>
        <table class="extended" cellspacing="0">
            <tbody>
                <tr>
                    <th><label for="user_id">Uživatel <span class="required">*</span></label></th>
                    <td>
                        <select id="user_id" name="user_id">
                            <option value="">— vyberte uživatele —</option>
                            @foreach($users as $u)
                                <option value="{{ $u->id }}"
                                    @selected(old('user_id', $user?->id) == $u->id)>
                                    {{ $u->surname }} {{ $u->name }} – {{ $u->login }}
                                </option>
                            @endforeach
                        </select>
                        @error('user_id') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="name">Název <span class="required">*</span></label></th>
                    <td>
                        <input type="text" id="name" name="name"
                               value="{{ old('name', $user ? $user->surname : '') }}" maxlength="255">
                        @error('name') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="type">Typ zařízení <span class="required">*</span></label></th>
                    <td>
                        <select id="type" name="type">
                            <option value="">— vyberte typ —</option>
                            @foreach($deviceTypes as $dt)
                                <option value="{{ $dt->id }}"
                                    @selected(old('type', $selectedTypeId) == $dt->id)>
                                    {{ $dt->value }}
                                </option>
                            @endforeach
                        </select>
                        @error('type') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="device_template_id">Šablona</label></th>
                    <td>
                        <select id="device_template_id" name="device_template_id"
                                onchange="reloadWithTemplate(this.value)">
                            <option value="">— bez šablony —</option>
                            {{-- populated by JS on load --}}
                        </select>
                        @error('device_template_id') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
            </tbody>
        </table>

        {{-- Section 2: Rozhraní (server-rendered from template) --}}
        @if($selectedTemplate && count($ifaceDefinitions) > 0)
            <h3>Rozhraní</h3>
            @foreach($ifaceDefinitions as $n => $iface)
                @if(($iface['count'] ?? 1) > 0)
                    @php $item = $iface['items'][0] ?? []; @endphp
                    <fieldset style="margin-bottom:1em; border:1px solid #ccc; padding:0.8em;">
                        <legend>Rozhraní {{ $n + 1 }}: {{ $iface['type_label'] }}
                            ({{ $item['name'] ?? '' }})</legend>
                        <table class="extended" cellspacing="0">
                            <tbody>
                                <tr>
                                    <th><label>Název <span class="required">*</span></label></th>
                                    <td>
                                        <input type="text" name="iface_name_{{ $n }}"
                                               value="{{ old("iface_name_{$n}", $item['name'] ?? '') }}"
                                               maxlength="254">
                                        @error("iface_name_{$n}") <span class="error">{{ $message }}</span> @enderror
                                    </td>
                                </tr>
                                @if($iface['has_mac'])
                                    <tr>
                                        <th><label>MAC adresa</label></th>
                                        <td>
                                            <input type="text" name="iface_mac_{{ $n }}"
                                                   value="{{ old("iface_mac_{$n}") }}"
                                                   placeholder="aa:bb:cc:dd:ee:ff" maxlength="20">
                                            @error("iface_mac_{$n}") <span class="error">{{ $message }}</span> @enderror
                                        </td>
                                    </tr>
                                @endif
                                @if($iface['has_ip'])
                                    <tr>
                                        <th><label>IP adresa</label></th>
                                        <td>
                                            <input type="text" name="iface_ip_{{ $n }}"
                                                   value="{{ old("iface_ip_{$n}") }}"
                                                   placeholder="10.133.23.x">
                                            @error("iface_ip_{$n}") <span class="error">{{ $message }}</span> @enderror
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><label>Podsíť</label></th>
                                        <td>
                                            <select name="iface_subnet_{{ $n }}">
                                                <option value="">— vyberte podsíť —</option>
                                                @foreach($subnets as $subnet)
                                                    <option value="{{ $subnet->id }}"
                                                        @selected(old("iface_subnet_{$n}") == $subnet->id)>
                                                        {{ $subnet->label }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            @error("iface_subnet_{$n}") <span class="error">{{ $message }}</span> @enderror
                                        </td>
                                    </tr>
                                @endif
                            </tbody>
                        </table>
                    </fieldset>
                @endif
            @endforeach
        @elseif(!$selectedTemplate)
            <p style="color:#888; font-style:italic;">
                Vyberte šablonu pro zobrazení polí rozhraní.
            </p>
        @endif

        {{-- Section 3: Detail zařízení --}}
        <h3>
            <a href="#" onclick="this.parentElement.nextElementSibling.style.display=
                this.parentElement.nextElementSibling.style.display==='none'?'block':'none'; return false;">
                Detail zařízení ▾
            </a>
        </h3>
        <div id="device-detail" style="display:none;">
            <table class="extended" cellspacing="0">
                <tbody>
                    <tr>
                        <th><label for="buy_date">Datum koupě</label></th>
                        <td>
                            <input type="date" id="buy_date" name="buy_date" value="{{ old('buy_date') }}">
                            @error('buy_date') <span class="error">{{ $message }}</span> @enderror
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
        </div>

        <p><input type="submit" value="Přidat zařízení" form="device-add-form"></p>
    </form>

    <script>
    const allTemplates = @json($templates);
    const selectedTemplateId = {{ $selectedTemplate ? $selectedTemplate->id : 'null' }};

    function renderTemplateOptions(typeId) {
        const select = document.getElementById('device_template_id');
        const current = selectedTemplateId;
        select.innerHTML = '<option value="">— bez šablony —</option>';
        allTemplates
            .filter(t => !typeId || t.enum_type_id == typeId)
            .forEach(t => {
                const opt = document.createElement('option');
                opt.value = t.id;
                opt.text = t.name;
                if (t.id == current) opt.selected = true;
                select.appendChild(opt);
            });
    }

    // Initial render based on current type selection
    const typeSelect = document.getElementById('type');
    renderTemplateOptions(parseInt(typeSelect.value) || 0);

    typeSelect.addEventListener('change', function () {
        renderTemplateOptions(parseInt(this.value) || 0);
    });

    function reloadWithTemplate(templateId) {
        var url = new URL(window.location.href);
        url.searchParams.set('template_id', templateId);
        var userId = document.getElementById('user_id').value;
        if (userId) url.searchParams.set('user_id', userId);
        var typeId = document.getElementById('type').value;
        if (typeId) url.searchParams.set('type_id', typeId);
        window.location.href = url.toString();
    }
    </script>
@endsection
