@extends('layouts.app')
@section('title', 'Přidat zařízení')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('devices.index') }}">Zařízení</a> &raquo; Přidat zařízení
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Přidat zařízení</h2></div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

<form method="POST" action="{{ route('devices.store_template') }}" id="device-add-form">
@csrf
@if(!empty($connectionRequestId))
    <input type="hidden" name="connection_request_id" value="{{ $connectionRequestId }}">
@endif

<div class="m-card" style="margin-bottom:16px;max-width:560px">
    <div class="m-card-title">Zařízení</div>
    <div class="m-form-group">
        <label class="m-form-label" for="member_id">Člen <span style="color:#c0392b">*</span></label>
        <select class="m-form-select" id="member_id" name="member_id">
            <option value="">— vyberte člena —</option>
            @foreach($members as $m)
                <option value="{{ $m->id }}" @selected(old('member_id', $preselectedMemberId ?? null) == $m->id)>
                    {{ $m->name }} – {{ $m->login }}
                </option>
            @endforeach
        </select>
        @error('member_id') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="name">Název <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="text" id="name" name="name"
                   value="{{ old('name', $user ? $user->surname : '') }}" maxlength="255">
            @error('name') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="type">Typ zařízení <span style="color:#c0392b">*</span></label>
            <select class="m-form-select" id="type" name="type">
                <option value="">— vyberte typ —</option>
                @foreach($deviceTypes as $dt)
                    <option value="{{ $dt->id }}" @selected(old('type', $selectedTypeId) == $dt->id)>
                        {{ $dt->value }}
                    </option>
                @endforeach
            </select>
            @error('type') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="m-form-group">
        <label class="m-form-label" for="device_template_id">Šablona</label>
        <select class="m-form-select" id="device_template_id" name="device_template_id"
                onchange="reloadWithTemplate(this.value)" style="max-width:280px">
            <option value="">— bez šablony —</option>
        </select>
        @error('device_template_id') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
</div>

@if($selectedTemplate && count($ifaceDefinitions) > 0)
@foreach($ifaceDefinitions as $n => $iface)
@if(($iface['count'] ?? 1) > 0)
@php $item = $iface['items'][0] ?? []; @endphp
<div class="m-card" style="margin-bottom:16px;max-width:560px">
    <div class="m-card-title">Rozhraní {{ $n + 1 }}: {{ $iface['type_label'] }} ({{ $item['name'] ?? '' }})</div>
    <div class="m-form-group">
        <label class="m-form-label">Název <span style="color:#c0392b">*</span></label>
        <input class="m-form-input" type="text" name="iface_name_{{ $n }}"
               value="{{ old("iface_name_{$n}", $item['name'] ?? '') }}" maxlength="254">
        @error("iface_name_{$n}") <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    @if($iface['has_mac'])
    <div class="m-form-group">
        <label class="m-form-label">MAC adresa</label>
        <input class="m-form-input" type="text" name="iface_mac_{{ $n }}"
               value="{{ old("iface_mac_{$n}", $n === 0 ? ($preselectedMac ?? '') : '') }}"
               placeholder="aa:bb:cc:dd:ee:ff" maxlength="20" style="font-family:monospace;max-width:200px">
        @error("iface_mac_{$n}") <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    @endif
    @if($iface['has_ip'])
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label">IP adresa</label>
            <input class="m-form-input" type="text" name="iface_ip_{{ $n }}"
                   value="{{ old("iface_ip_{$n}", $n === 0 ? ($preselectedIp ?? '') : '') }}"
                   placeholder="10.133.23.x" style="font-family:monospace">
            @error("iface_ip_{$n}") <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label">Podsíť</label>
            <select class="m-form-select" name="iface_subnet_{{ $n }}">
                <option value="">— vyberte podsíť —</option>
                @foreach($subnets as $subnet)
                    <option value="{{ $subnet->id }}"
                        @selected(old("iface_subnet_{$n}", $n === 0 ? ($preselectedSubnetId ?? '') : '') == $subnet->id)>
                        {{ $subnet->label }}
                    </option>
                @endforeach
            </select>
            @error("iface_subnet_{$n}") <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
    @endif
</div>
@endif
@endforeach
@elseif(!$selectedTemplate)
<div class="m-alert m-alert-info" style="max-width:560px">Vyberte šablonu pro zobrazení polí rozhraní.</div>
@endif

<div class="m-card" style="margin-bottom:16px;max-width:560px">
    <div class="m-card-title">
        <a href="#" onclick="this.closest('.m-card').querySelector('.detail-fields').style.display=this.closest('.m-card').querySelector('.detail-fields').style.display==='none'?'block':'none'; return false;" style="color:#aaa;text-decoration:none">
            Detail zařízení ▾
        </a>
    </div>
    <div class="detail-fields" style="display:none">
        <div class="m-form-group">
            <label class="m-form-label" for="buy_date">Datum koupě</label>
            <input class="m-form-input" type="date" id="buy_date" name="buy_date" value="{{ old('buy_date') }}" style="max-width:180px">
            @error('buy_date') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="comment">Komentář</label>
            <textarea class="m-form-input" id="comment" name="comment" rows="3" maxlength="254">{{ old('comment') }}</textarea>
            @error('comment') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
</div>

<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit" form="device-add-form">Přidat zařízení</button>
    <a class="m-btn" href="{{ route('devices.index') }}">Zrušit</a>
</div>
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

const typeSelect = document.getElementById('type');
renderTemplateOptions(parseInt(typeSelect.value) || 0);
typeSelect.addEventListener('change', function() { renderTemplateOptions(parseInt(this.value) || 0); });

function reloadWithTemplate(templateId) {
    var url = new URL(window.location.href);
    url.searchParams.set('template_id', templateId);
    var memberId = document.getElementById('member_id').value;
    if (memberId) url.searchParams.set('member_id', memberId);
    var typeId = document.getElementById('type').value;
    if (typeId) url.searchParams.set('type_id', typeId);
    window.location.href = url.toString();
}

const subnetData = @json($subnetData ?? []);

function ipToLong(ip) {
    return ip.split('.').reduce((acc, oct) => (acc << 8) + parseInt(oct), 0) >>> 0;
}
function findSubnetForIp(ip) {
    const ipLong = ipToLong(ip);
    return subnetData.find(s => {
        const network = ipToLong(s.network), mask = ipToLong(s.mask);
        return (ipLong & mask) === (network & mask);
    });
}
function setupIpAutocomplete(ipInput, subnetSelect) {
    if (!ipInput) return;
    const allFreeIps = [];
    subnetData.forEach(s => s.freeIps.forEach(ip => allFreeIps.push(ip)));
    const listId = 'datalist_' + ipInput.name.replace(/[^a-z0-9]/gi, '_');
    let datalist = document.getElementById(listId);
    if (!datalist) { datalist = document.createElement('datalist'); datalist.id = listId; document.body.appendChild(datalist); }
    ipInput.setAttribute('list', listId);
    ipInput.setAttribute('autocomplete', 'off');
    ipInput.addEventListener('input', function() {
        const val = this.value;
        datalist.innerHTML = '';
        if (val.length < 3) return;
        allFreeIps.filter(ip => ip.startsWith(val)).slice(0, 10).forEach(ip => {
            const opt = document.createElement('option'); opt.value = ip; datalist.appendChild(opt);
        });
    });
    function autofillSubnet() {
        const ip = ipInput.value;
        if (!/^\d+\.\d+\.\d+\.\d+$/.test(ip)) return;
        const subnet = findSubnetForIp(ip);
        if (subnet && subnetSelect) subnetSelect.value = subnet.id;
    }
    ipInput.addEventListener('change', autofillSubnet);
    ipInput.addEventListener('blur', autofillSubnet);
}
document.querySelectorAll('input[name^="iface_ip_"]').forEach(ipInput => {
    const n = ipInput.name.replace('iface_ip_', '');
    const subnetSelect = document.querySelector('select[name="iface_subnet_' + n + '"]');
    setupIpAutocomplete(ipInput, subnetSelect);
});
</script>
</div>
@endsection
