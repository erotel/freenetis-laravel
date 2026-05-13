@extends('layouts.app')
@section('title', 'Nová žádost o připojení')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('connection_requests.index') }}">Žádosti o připojení</a> &raquo; Nová žádost
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Nová žádost o připojení</h2></div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

<form method="POST" action="{{ route('connection_requests.store') }}">
@csrf
<input type="hidden" name="subnet_id" value="{{ $subnet->id }}">

<div class="m-card" style="margin-bottom:16px;max-width:560px">
    @if($canEditDevices)
    <div class="m-form-group">
        <label class="m-form-label">Vlastník připojení <span style="color:#c0392b">*</span></label>
        <select class="m-form-select" name="member_id" required>
            <option value="">— vyberte člena —</option>
            @foreach($members as $id => $name)
                <option value="{{ $id }}" @selected(old('member_id', $authMemberId) == $id)>{{ $name }}</option>
            @endforeach
        </select>
    </div>
    @endif
    <div class="m-field" style="margin-bottom:8px">
        <span class="m-field-label">Subnet</span>
        <span class="m-field-value" style="font-family:monospace">{{ $subnet->name }} ({{ $subnet->network_address }}/{{ $subnet->netmask }})</span>
    </div>
    <div class="m-field" style="margin-bottom:8px">
        <span class="m-field-label">IP adresa</span>
        <span class="m-field-value" style="font-family:monospace;font-weight:500">{{ $ipAddress }}</span>
        <input type="hidden" name="ip_address" value="{{ $ipAddress }}">
    </div>
    <div class="m-form-group">
        <label class="m-form-label">MAC adresa <span style="color:#c0392b">*</span></label>
        @php $macValue = old('mac_address', $detectedMac ?? ''); @endphp
        @if($detectedMac && !$canEditDevices)
            <div class="m-form-input" style="background:var(--fn-quote-bg);color:var(--fn-text);cursor:default;font-family:monospace">{{ $detectedMac }}</div>
            <input type="hidden" name="mac_address" value="{{ $detectedMac }}">
            <div class="m-form-hint">Automaticky detekováno přes SNMP.</div>
        @else
            <input class="m-form-input" type="text" name="mac_address" value="{{ $macValue }}"
                   required placeholder="AA:BB:CC:DD:EE:FF" style="font-family:monospace;max-width:200px">
            <div class="m-form-hint">Formát: AA:BB:CC:DD:EE:FF</div>
        @endif
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label">Typ zařízení <span style="color:#c0392b">*</span></label>
            <select class="m-form-select" name="device_type_id" id="device_type_id" required
                    onchange="filterTemplates(this.value)">
                <option value="">— vyberte typ —</option>
                @foreach($deviceTypes as $id => $label)
                    <option value="{{ $id }}" @selected(old('device_type_id', $defaultType) == $id)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="m-form-group">
            <label class="m-form-label">Šablona zařízení <span style="color:#c0392b">*</span></label>
            <select class="m-form-select" name="device_template_id" id="device_template_id" required>
                <option value="">— vyberte šablonu —</option>
                @foreach($templates as $t)
                    <option value="{{ $t->id }}" data-type="{{ $t->enum_type_id }}"
                            @selected(old('device_template_id') == $t->id)>
                        {{ $t->name }}
                    </option>
                @endforeach
            </select>
            @error('device_template_id') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="m-form-group">
        <label class="m-form-label">Komentář</label>
        <textarea class="m-form-input" name="comment" rows="3">{{ old('comment') }}</textarea>
    </div>
</div>

<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Odeslat žádost</button>
    <a class="m-btn" href="{{ route('connection_requests.index') }}">Zrušit</a>
</div>
</form>

<script>
function filterTemplates(typeId) {
    var select = document.getElementById('device_template_id');
    var options = select.querySelectorAll('option[data-type]');
    options.forEach(function(opt) {
        var show = !typeId || opt.getAttribute('data-type') === typeId;
        opt.style.display = show ? '' : 'none';
        if (!show && opt.selected) { opt.selected = false; select.value = ''; }
    });
}
document.addEventListener('DOMContentLoaded', function() {
    var typeSelect = document.getElementById('device_type_id');
    if (typeSelect.value) filterTemplates(typeSelect.value);
});
</script>
</div>
@endsection
