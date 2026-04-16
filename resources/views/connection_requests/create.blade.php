@extends('layouts.app')

@section('title', 'Nová žádost o připojení')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('connection_requests.index') }}">Žádosti o připojení</a> &raquo;
        Nová žádost
    </div>
@endsection

@section('content')
<h2>Nová žádost o připojení</h2>

@if($errors->any())
    <div style="color:#c00; margin-bottom:1em;">
        <ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

<form method="POST" action="{{ route('connection_requests.store') }}">
    @csrf
    <input type="hidden" name="subnet_id" value="{{ $subnet->id }}">

    <table class="extended" cellspacing="0">
        <tbody>
            @if($canEditDevices)
            <tr>
                <th>Vlastník připojení <span style="color:#c00;">*</span></th>
                <td>
                    <select name="member_id" required style="width:250px;">
                        <option value="">— vyberte člena —</option>
                        @foreach($members as $id => $name)
                            <option value="{{ $id }}" @selected(old('member_id', $authMemberId) == $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                </td>
            </tr>
            @endif
            <tr>
                <th>Subnet</th>
                <td>{{ $subnet->name }} ({{ $subnet->network_address }}/{{ $subnet->netmask }})</td>
            </tr>
            <tr>
                <th>IP adresa</th>
                <td>
                    <strong>{{ $ipAddress }}</strong>
                    <input type="hidden" name="ip_address" value="{{ $ipAddress }}">
                </td>
            </tr>
            <tr>
                <th>MAC adresa <span style="color:#c00;">*</span></th>
                <td>
                    @php $macValue = old('mac_address', $detectedMac ?? ''); @endphp
                    @if($detectedMac && !$canEditDevices)
                        <strong>{{ $detectedMac }}</strong>
                        <input type="hidden" name="mac_address" value="{{ $detectedMac }}">
                        <br><small style="color:#888;">Automaticky detekováno přes SNMP.</small>
                    @else
                        <input type="text" name="mac_address" value="{{ $macValue }}"
                               required style="width:150px;" placeholder="AA:BB:CC:DD:EE:FF">
                        <br><small style="color:#888;">Formát: AA:BB:CC:DD:EE:FF</small>
                    @endif
                </td>
            </tr>
            <tr>
                <th>Typ zařízení <span style="color:#c00;">*</span></th>
                <td>
                    <select name="device_type_id" id="device_type_id" required style="width:200px;"
                            onchange="filterTemplates(this.value)">
                        <option value="">— vyberte typ —</option>
                        @foreach($deviceTypes as $id => $label)
                            <option value="{{ $id }}" @selected(old('device_type_id', $defaultType) == $id)>{{ $label }}</option>
                        @endforeach
                    </select>
                </td>
            </tr>
            <tr>
                <th>Šablona zařízení <span style="color:#c00;">*</span></th>
                <td>
                    <select name="device_template_id" id="device_template_id" required style="width:200px;">
                        <option value="">— vyberte šablonu —</option>
                        @foreach($templates as $t)
                            <option value="{{ $t->id }}"
                                    data-type="{{ $t->enum_type_id }}"
                                    @selected(old('device_template_id') == $t->id)>
                                {{ $t->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('device_template_id') <span class="error">{{ $message }}</span> @enderror
                </td>
            </tr>
            <tr>
                <th>Komentář</th>
                <td>
                    <textarea name="comment" style="width:350px; height:80px;">{{ old('comment') }}</textarea>
                </td>
            </tr>
        </tbody>
    </table>

    <div style="margin-top:1em;">
        <button type="submit">Odeslat žádost</button>
        &nbsp;
        <a href="{{ route('connection_requests.index') }}">Zrušit</a>
    </div>
</form>

<script>
function filterTemplates(typeId) {
    var select = document.getElementById('device_template_id');
    var options = select.querySelectorAll('option[data-type]');
    var hasVisible = false;

    options.forEach(function(opt) {
        var show = !typeId || opt.getAttribute('data-type') === typeId;
        opt.style.display = show ? '' : 'none';
        if (show) hasVisible = true;
        if (!show && opt.selected) {
            opt.selected = false;
            select.value = '';
        }
    });
}

// Run on load if type already selected
document.addEventListener('DOMContentLoaded', function() {
    var typeSelect = document.getElementById('device_type_id');
    if (typeSelect.value) filterTemplates(typeSelect.value);
});
</script>
@endsection
