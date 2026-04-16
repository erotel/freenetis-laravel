@extends('layouts.app')
@section('title', 'Upravit rozhraní: ' . $iface->name)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('devices.index') }}">Zařízení</a> &raquo;
    <a href="{{ route('devices.show', $iface->device_id) }}">{{ $iface->device->name }}</a> &raquo;
    <a href="{{ route('ifaces.show', $iface->id) }}">Rozhraní {{ $iface->name }}</a> &raquo;
    Upravit
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Upravit rozhraní: {{ $iface->name }}</h2></div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

<form method="POST" action="{{ route('ifaces.update', $iface->id) }}">
@csrf
@method('PUT')

<div class="m-card" style="margin-bottom:16px;max-width:480px">
    <div class="m-field" style="margin-bottom:8px">
        <span class="m-field-label">Typ</span>
        <span class="m-field-value">{{ $iface->typeLabelAttribute }}</span>
    </div>
    <div class="m-field" style="margin-bottom:12px">
        <span class="m-field-label">Zařízení</span>
        <span class="m-field-value"><a href="{{ route('devices.show', $iface->device_id) }}">{{ $iface->device->name }}</a></span>
    </div>
    <div class="m-form-group">
        <label class="m-form-label" for="name">Název <span style="color:#c0392b">*</span></label>
        <input class="m-form-input" type="text" id="name" name="name"
               value="{{ old('name', $iface->name) }}" maxlength="254">
        @error('name') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    <div class="m-form-group">
        <label class="m-form-label" for="mac">MAC adresa</label>
        <input class="m-form-input" type="text" id="mac" name="mac"
               value="{{ old('mac', $iface->mac) }}" maxlength="20"
               placeholder="aa:bb:cc:dd:ee:ff" style="font-family:monospace;max-width:200px">
        <div class="m-form-hint">Formát: aa:bb:cc:dd:ee:ff</div>
        @error('mac') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    <div class="m-form-group">
        <label class="m-form-label" for="comment">Komentář</label>
        <textarea class="m-form-input" id="comment" name="comment" rows="3" maxlength="254">{{ old('comment', $iface->comment) }}</textarea>
        @error('comment') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
</div>

@if($iface->ipAddresses->count() > 0)
<div class="m-section">IP adresy</div>
<div class="m-card" style="margin-bottom:16px;max-width:560px">
    @foreach($iface->ipAddresses as $n => $ip)
    <input type="hidden" name="ip_id_{{ $n }}" value="{{ $ip->id }}">
    <div class="m-form-row" style="margin-bottom:8px">
        <div class="m-form-group">
            <label class="m-form-label">IP adresa</label>
            <input class="m-form-input" type="text" name="ip_address_{{ $n }}"
                   value="{{ old("ip_address_{$n}", $ip->ip_address) }}" placeholder="10.0.0.1" style="font-family:monospace">
            @error("ip_address_{$n}") <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label">Podsíť</label>
            <select class="m-form-select" name="ip_subnet_{{ $n }}">
                <option value="">— vyberte podsíť —</option>
                @foreach($subnets as $subnet)
                    <option value="{{ $subnet->id }}" @selected(old("ip_subnet_{$n}", $ip->subnet_id) == $subnet->id)>
                        {{ $subnet->label }}
                    </option>
                @endforeach
            </select>
            @error("ip_subnet_{$n}") <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
    <div style="margin-bottom:8px">
        <a class="m-link-sm" href="{{ route('ip_addresses.edit', $ip->id) }}">Upravit IP adresu</a>
    </div>
    @endforeach
</div>
@endif

<div class="m-section">Přidat IP adresu</div>
<div class="m-card" style="margin-bottom:16px;max-width:560px">
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="new_ip_address">IP adresa</label>
            <input class="m-form-input" type="text" id="new_ip_address" name="new_ip_address"
                   value="{{ old('new_ip_address') }}" placeholder="10.0.0.1" style="font-family:monospace">
            @error('new_ip_address') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="new_ip_subnet">Podsíť</label>
            <select class="m-form-select" id="new_ip_subnet" name="new_ip_subnet">
                <option value="">— vyberte podsíť —</option>
                @foreach($subnets as $subnet)
                    <option value="{{ $subnet->id }}" @selected(old('new_ip_subnet') == $subnet->id)>
                        {{ $subnet->label }}
                    </option>
                @endforeach
            </select>
            @error('new_ip_subnet') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
</div>

<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Uložit</button>
    <a class="m-btn" href="{{ route('ifaces.show', $iface->id) }}">Zrušit</a>
</div>
</form>

<script>
const subnetData = @json($subnetData ?? []);
function ipToLong(ip) { return ip.split('.').reduce((acc, oct) => (acc << 8) + parseInt(oct), 0) >>> 0; }
function findSubnetForIp(ip) {
    const ipLong = ipToLong(ip);
    return subnetData.find(s => { const n = ipToLong(s.network), m = ipToLong(s.mask); return (ipLong & m) === (n & m); });
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
        const val = this.value; datalist.innerHTML = '';
        if (val.length < 3) return;
        allFreeIps.filter(ip => ip.startsWith(val)).slice(0, 10).forEach(ip => { const opt = document.createElement('option'); opt.value = ip; datalist.appendChild(opt); });
    });
    function autofill() {
        const ip = ipInput.value;
        if (!/^\d+\.\d+\.\d+\.\d+$/.test(ip)) return;
        const s = findSubnetForIp(ip);
        if (s && subnetSelect) subnetSelect.value = s.id;
    }
    ipInput.addEventListener('change', autofill);
    ipInput.addEventListener('blur', autofill);
}
document.querySelectorAll('input[name^="ip_address_"]').forEach(ipInput => {
    const n = ipInput.name.replace('ip_address_', '');
    setupIpAutocomplete(ipInput, document.querySelector('select[name="ip_subnet_' + n + '"]'));
});
setupIpAutocomplete(document.getElementById('new_ip_address'), document.getElementById('new_ip_subnet'));
</script>
</div>
@endsection
