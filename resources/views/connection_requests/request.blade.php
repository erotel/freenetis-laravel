@extends('layouts.app')
@section('title', 'Požádat o nové připojení')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    @if(auth()->user()?->member_id)
    <a href="{{ route('connection_requests.by_member', auth()->user()->member_id) }}">Žádosti o připojení</a> &raquo;
    @endif
    Nová žádost
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Požádat o nové připojení</h2></div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

<div class="m-card" style="max-width:620px;margin-bottom:16px;font-size:14px;line-height:1.55">
    Vyplňte, o jaké připojení / zařízení máte zájem. Technik vám přidělí IP adresu
    a zařízení nastaví. Do poznámky můžete napsat upřesnění (např. kam zařízení patří).
</div>

<form method="POST" action="{{ route('connection_requests.request_store') }}">
@csrf

<div class="m-card" style="margin-bottom:16px;max-width:620px">
    @php $hasDetectedMac = $detected && !empty($detected['mac']); @endphp

    @if($detected)
    <div class="m-alert m-alert-info" style="margin-bottom:14px">
        Zjistili jsme nové zařízení na vaší IP adrese <strong style="font-family:monospace">{{ $detected['ip'] }}</strong>@if($detected['mac']) (MAC <span style="font-family:monospace">{{ $detected['mac'] }}</span>)@endif.
        Tyto údaje k žádosti automaticky přiložíme.
    </div>
    @endif

    <div class="m-form-group">
        <label class="m-form-label">MAC adresa zařízení <span style="color:#c0392b">*</span></label>
        @if($hasDetectedMac)
            <div class="m-form-input" style="background:var(--fn-quote-bg);color:var(--fn-text);cursor:default;font-family:monospace">{{ $detected['mac'] }}</div>
            <div class="m-form-hint">Automaticky detekováno přes SNMP.</div>
        @else
            <input class="m-form-input" type="text" name="mac_address" value="{{ old('mac_address') }}"
                   required placeholder="AA:BB:CC:DD:EE:FF" style="font-family:monospace;max-width:220px">
            <div class="m-form-hint">Bez MAC adresy technik zařízení nedohledá. Najdete ji na štítku zařízení. Formát: AA:BB:CC:DD:EE:FF</div>
        @endif
    </div>

    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label">Typ zařízení</label>
            <select class="m-form-select" name="device_type_id" id="device_type_id" onchange="filterTemplates(this.value)">
                <option value="">— nevím / nechám na technikovi —</option>
                @foreach($deviceTypes as $id => $label)
                    <option value="{{ $id }}" @selected(old('device_type_id', $defaultType) == $id)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="m-form-group">
            <label class="m-form-label">Šablona zařízení</label>
            <select class="m-form-select" name="device_template_id" id="device_template_id">
                <option value="">— nevím —</option>
                @foreach($templates as $t)
                    <option value="{{ $t->id }}" data-type="{{ $t->enum_type_id }}"
                            @selected(old('device_template_id') == $t->id)>{{ $t->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="m-form-group">
        <label class="m-form-label">Poznámka</label>
        <textarea class="m-form-input" name="comment" rows="3" placeholder="Např. druhé zařízení do ložnice, kabelová přípojka…">{{ old('comment') }}</textarea>
    </div>
</div>

<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Odeslat žádost</button>
    @if(auth()->user()?->member_id)
    <a class="m-btn" href="{{ route('connection_requests.by_member', auth()->user()->member_id) }}">Zrušit</a>
    @endif
</div>
</form>

<script>
function filterTemplates(typeId) {
    var select = document.getElementById('device_template_id');
    if (!select) return;
    select.querySelectorAll('option[data-type]').forEach(function(opt) {
        var show = !typeId || opt.getAttribute('data-type') === typeId;
        opt.style.display = show ? '' : 'none';
        if (!show && opt.selected) { opt.selected = false; select.value = ''; }
    });
}
document.addEventListener('DOMContentLoaded', function() {
    var t = document.getElementById('device_type_id');
    if (t && t.value) filterTemplates(t.value);
});
</script>
</div>
@endsection
