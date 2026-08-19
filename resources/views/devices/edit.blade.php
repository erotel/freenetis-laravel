@extends('layouts.app')
@section('title', 'Upravit zařízení: ' . $device->name)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('devices.index') }}">Zařízení</a> &raquo;
    <a href="{{ route('devices.show', $device->id) }}">{{ $device->name }}</a> &raquo;
    Upravit
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Upravit zařízení: {{ $device->name }}</h2></div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

<form method="POST" action="{{ route('devices.update', $device->id) }}">
@csrf
@method('PUT')
<div class="m-card" style="margin-bottom:16px;max-width:560px">
    <div class="m-form-group">
        <label class="m-form-label" for="member_id">Člen <span style="color:#c0392b">*</span></label>
        <select class="m-form-select" id="member_id" name="member_id">
            <option value="">— vyberte člena —</option>
            @foreach($members as $m)
                <option value="{{ $m->id }}" @selected(old('member_id', $currentMemberId) == $m->id)>
                    {{ $m->display_name }} ({{ $m->login }})
                </option>
            @endforeach
        </select>
        @error('member_id') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    <div class="m-form-group">
        <div class="m-form-row" style="justify-content:space-between;align-items:baseline">
            <label class="m-form-label">Adresa umístění</label>
            <button type="button" class="btnlink" id="address_from_member_btn" style="background:none;border:0;color:#2563eb;cursor:pointer;font-size:14px;padding:0" title="Přepsat adresu adresou vybraného člena">↻ Převzít z člena</button>
        </div>
        <div class="m-form-row">
            <div class="m-form-group">
                <label class="m-form-label" for="town_id" style="font-weight:400;color:var(--fn-muted)">Město</label>
                <select class="m-form-select" id="town_id" name="town_id">
                    <option value="">— vyberte město —</option>
                    @foreach($towns as $t)
                        <option value="{{ $t->id }}" @selected(old('town_id', $currentTownId) == $t->id)>{{ $t->town }} {{ $t->zip_code }}</option>
                    @endforeach
                </select>
                @error('town_id') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
            </div>
            <div class="m-form-group">
                <label class="m-form-label" for="street_id" style="font-weight:400;color:var(--fn-muted)">Ulice</label>
                <select class="m-form-select" id="street_id" name="street_id" data-selected="{{ old('street_id', $currentStreetId) }}">
                    <option value="">— vyberte ulici —</option>
                </select>
                @error('street_id') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
            </div>
            <div class="m-form-group" style="flex:0 0 130px">
                <label class="m-form-label" for="street_number" style="font-weight:400;color:var(--fn-muted)">Číslo popisné</label>
                <input class="m-form-input" type="text" id="street_number" name="street_number" value="{{ old('street_number', $currentStreetNumber) }}" maxlength="50">
                @error('street_number') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
            </div>
        </div>
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="name">Název <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="text" id="name" name="name" value="{{ old('name', $device->name) }}" maxlength="255">
            @error('name') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="type">Typ <span style="color:#c0392b">*</span></label>
            <select class="m-form-select" id="type" name="type">
                <option value="">— vyberte typ —</option>
                @foreach($deviceTypes as $id => $label)
                    <option value="{{ $id }}" @selected(old('type', $device->type) == $id)>{{ $label }}</option>
                @endforeach
            </select>
            @error('type') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="trade_name">Obchodní název</label>
            <input class="m-form-input" type="text" id="trade_name" name="trade_name" value="{{ old('trade_name', $device->trade_name) }}" maxlength="50">
            @error('trade_name') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="buy_date">Datum koupě</label>
            <input class="m-form-input" type="date" id="buy_date" name="buy_date" value="{{ old('buy_date', $device->buy_date) }}">
            @error('buy_date') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
    @if($canEditLogin)
    <div class="m-form-group">
        <label class="m-form-label" for="login">Login</label>
        <input class="m-form-input" type="text" id="login" name="login" value="{{ old('login', $device->login) }}" maxlength="30">
        @error('login') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    @endif
    @if($canEditPassword)
    <div class="m-form-group">
        <label class="m-form-label" for="password">Heslo</label>
        <input class="m-form-input" type="text" id="password" name="password" value="{{ old('password', $device->password) }}" maxlength="30">
        @error('password') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    <div class="m-form-group" id="wpa_field" style="{{ (int) old('type', $device->type) === (int) $apTypeId ? '' : 'display:none' }}">
        <label class="m-form-label" for="wpa_key">Šifrovací klíč (WPA2)</label>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <input class="m-form-input" type="password" id="wpa_key" name="wpa_key"
                   value="{{ old('wpa_key', $device->wpa_key) }}" minlength="16" maxlength="63"
                   autocomplete="new-password" style="flex:1;min-width:220px">
            <button type="button" class="m-btn" onclick="wpaToggle()">Zobrazit</button>
            <button type="button" class="m-btn" onclick="wpaGenerate()">Generovat</button>
        </div>
        <div class="m-form-hint">WPA2‑PSK klíč přístupového bodu. Doporučeno ≥ 16 znaků (generátor dělá 20). Uloženo šifrovaně.</div>
        @error('wpa_key') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    @endif
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="price">Cena</label>
            <input class="m-form-input" type="number" step="0.01" id="price" name="price" value="{{ old('price', $device->price) }}">
            @error('price') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="payment_rate">Měsíční splátka</label>
            <input class="m-form-input" type="number" step="0.01" id="payment_rate" name="payment_rate" value="{{ old('payment_rate', $device->payment_rate) }}">
            @error('payment_rate') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="m-form-group">
        <label class="m-form-label" for="comment">Komentář</label>
        <textarea class="m-form-input" id="comment" name="comment" rows="3" maxlength="254">{{ old('comment', $device->comment) }}</textarea>
        @error('comment') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
</div>
<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Uložit</button>
    <a class="m-btn" href="{{ route('devices.show', $device->id) }}">Zrušit</a>
</div>
</form>
</div>

<script>
(function () {
    // Mapa člen → adresa umístění (rozložená na město/ulici/číslo).
    @php $memberAddrMap = $members->mapWithKeys(fn($m) => [$m->id => ['town' => $m->ap_town_id, 'street' => $m->ap_street_id, 'number' => $m->ap_street_number]]); @endphp
    var memberAddr = @json($memberAddrMap);
    var townSel   = document.getElementById('town_id');
    var streetSel = document.getElementById('street_id');
    var numberInp = document.getElementById('street_number');
    var select    = document.getElementById('member_id');
    var btn       = document.getElementById('address_from_member_btn');

    function loadStreets(townId, selectedId) {
        streetSel.innerHTML = '<option value="">— vyberte ulici —</option>';
        if (!townId) return;
        fetch('{{ url('streets/by-town') }}/' + townId)
            .then(function (r) { return r.json(); })
            .then(function (streets) {
                streets.forEach(function (s) {
                    var opt = document.createElement('option');
                    opt.value = s.id; opt.textContent = s.street;
                    if (selectedId && s.id == selectedId) opt.selected = true;
                    streetSel.appendChild(opt);
                });
            });
    }
    townSel.addEventListener('change', function () { loadStreets(this.value, null); });

    // Na startu předvyplň ulice podle aktuálního města zařízení.
    loadStreets(townSel.value, streetSel.getAttribute('data-selected') || null);

    // Adresu automaticky NEpřepisujeme (zařízení může být jinde než člen) —
    // admin ji převezme z člena tlačítkem, jinak si vybere ručně město/ulici/číslo.
    if (btn && select) {
        btn.addEventListener('click', function () {
            var a = memberAddr[select.value];
            if (!a) return;
            townSel.value   = a.town || '';
            numberInp.value = a.number || '';
            loadStreets(a.town, a.street);
        });
    }
})();
</script>

@if($canEditPassword)
<script>
// Šifrovací klíč (WPA2) — pole jen u typu AP; generátor + odkrytí.
var WPA_AP_TYPE = {{ (int) $apTypeId }};
function wpaToggleField() {
    var t = document.getElementById('type');
    var f = document.getElementById('wpa_field');
    if (!t || !f) return;
    f.style.display = (parseInt(t.value, 10) === WPA_AP_TYPE) ? '' : 'none';
}
function wpaToggle() {
    var i = document.getElementById('wpa_key');
    if (i) i.type = (i.type === 'password') ? 'text' : 'password';
}
function wpaGenerate() {
    // 20 znaků z bezpečné abecedy (bez matoucích 0/O/1/l/I) ≈ 118 bitů entropie.
    var abc = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    var out = '', a = new Uint32Array(20);
    (window.crypto || window.msCrypto).getRandomValues(a);
    for (var k = 0; k < a.length; k++) out += abc[a[k] % abc.length];
    var i = document.getElementById('wpa_key');
    if (i) { i.type = 'text'; i.value = out; }
}
(function () {
    var t = document.getElementById('type');
    if (t) t.addEventListener('change', wpaToggleField);
    wpaToggleField();
})();
</script>
@endif
@endsection
