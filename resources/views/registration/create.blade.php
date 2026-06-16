@extends('layouts.app')
@section('title', 'Registrace')
@section('menu') @endsection
@section('breadcrumbs') @endsection
@section('content')
<div style="max-width:700px; margin:2em auto">
<div class="m-title-row"><h2>Registrace</h2></div>
<div class="m-subtitle">Vyplňte registrační formulář. Po odeslání vás bude kontaktovat správce sítě.</div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">
        @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
    </ul>
</div>
@endif

<form method="POST" action="{{ route('registration.store') }}">
@csrf

<div class="m-card" style="margin-bottom:16px;padding:14px 1.25rem">
    <div class="m-card-title">Typ registrace</div>
    <div style="display:flex;gap:16px;flex-wrap:wrap">
        <label style="flex:1;min-width:140px;cursor:pointer">
            <input type="radio" name="registration_type" value="18"
                {{ old('registration_type', '18') == '18' ? 'checked' : '' }}
                style="display:none" class="reg-type-radio">
            <div class="reg-type-card m-card" style="border:2px solid #ddd;text-align:center;cursor:pointer;margin:0">
                <div style="font-size:2em;margin-bottom:6px">🌐</div>
                <div style="font-weight:600;color:#222">Zákazník</div>
                <div style="color:#888;font-size:14px;margin-top:4px">Internet domů / do firmy</div>
            </div>
        </label>
        <label style="flex:1;min-width:140px;cursor:pointer">
            <input type="radio" name="registration_type" value="17"
                {{ old('registration_type') == '17' ? 'checked' : '' }}
                style="display:none" class="reg-type-radio">
            <div class="reg-type-card m-card" style="border:2px solid #ddd;text-align:center;cursor:pointer;margin:0">
                <div style="font-size:2em;margin-bottom:6px">🤝</div>
                <div style="font-weight:600;color:#222">Člen spolku</div>
                <div style="color:#888;font-size:14px;margin-top:4px">Chci se stát členem spolku</div>
            </div>
        </label>
    </div>
</div>
<style>
.reg-type-radio:checked + .reg-type-card { border-color:#185FA5 !important; background:#E6F1FB; }
.reg-type-card:hover { border-color:#999 !important; }
</style>

<div class="m-card" style="margin-bottom:16px">
    <div class="m-card-title">Základní informace</div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="reg-name">Jméno / Název <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="text" name="name" id="reg-name" value="{{ old('name') }}" maxlength="100">
            @error('name') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="reg-surname">Příjmení</label>
            <input class="m-form-input" type="text" name="surname" id="reg-surname" value="{{ old('surname') }}" maxlength="100">
        </div>
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="reg-birthday">Datum narození <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="date" id="reg-birthday" name="birthday" value="{{ old('birthday') }}">
            @error('birthday') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div></div>
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="reg-ico">IČO</label>
            <div style="display:flex;gap:8px;align-items:center">
                <input class="m-form-input" type="text" name="organization_identifier" id="reg-ico"
                       value="{{ old('organization_identifier') }}" maxlength="8" placeholder="12345678"
                       autocomplete="off" inputmode="numeric" pattern="[0-9]*">
                <button class="m-btn" type="button" onclick="loadFromAresReg()" style="white-space:nowrap">🔍 ARES</button>
            </div>
            <span id="reg-ares-status" class="m-form-hint"></span>
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="reg-dic">DIČ</label>
            <input class="m-form-input" type="text" name="vat_organization_identifier" id="reg-dic"
                   value="{{ old('vat_organization_identifier') }}" maxlength="20"
                   autocomplete="off" placeholder="CZ12345678">
        </div>
    </div>
</div>

<div class="m-card" style="margin-bottom:16px">
    <div class="m-card-title">Přihlašovací údaje</div>
    <div class="m-form-group">
        <label class="m-form-label" for="reg-login">Login <span style="color:#c0392b">*</span></label>
        <input class="m-form-input" type="text" id="reg-login" name="login" value="{{ old('login') }}"
               minlength="5" maxlength="20" autocomplete="off">
        <div class="m-form-hint">5–20 znaků, unikátní</div>
        @error('login') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="reg-password">Heslo <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="password" id="reg-password" name="password" autocomplete="new-password"
                   minlength="{{ \App\Models\Setting::get('security_password_length', 8) }}"
                   class="main_password">
            <div class="password-meter" style="margin-top:4px">
                <div class="password-meter-bar"></div>
                <div class="password-meter-message" style="font-size:0.85em;color:#888"></div>
            </div>
            @php $pwdLen = (int) \App\Models\Setting::get('security_password_length', 8); $pwdLevel = (int) \App\Models\Setting::get('security_password_level', 3); @endphp
            <div class="m-form-hint">
                Minimálně {{ $pwdLen }} znaků.
                @if($pwdLevel >= 4) Musí obsahovat malá i velká písmena, číslici nebo speciální znak.
                @elseif($pwdLevel >= 3) Musí obsahovat malá i velká písmena nebo alespoň jednu číslici.
                @elseif($pwdLevel >= 2) Nesmí být příliš jednoduché.
                @endif
            </div>
            @error('password') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="reg-password-confirm">Heslo znovu <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="password" id="reg-password-confirm" name="password_confirmation" autocomplete="new-password">
        </div>
    </div>
</div>

<div class="m-card" style="margin-bottom:16px">
    <div class="m-card-title">Kontaktní informace</div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="reg-phone">Telefon <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="text" id="reg-phone" name="phone" value="{{ old('phone') }}"
                   placeholder="+420123456789" maxlength="30">
            @error('phone') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="reg-email">E-mail <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="email" id="reg-email" name="email" value="{{ old('email') }}" maxlength="255"
                   autocomplete="email" inputmode="email">
            @error('email') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
</div>

<div class="m-card" style="margin-bottom:16px">
    <div class="m-card-title">Adresa připojení</div>
    <div id="reg-ares-adresa" class="m-alert m-alert-success" style="display:none;margin-bottom:12px"></div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="reg-town">Město <span style="color:#c0392b">*</span></label>
            <select class="m-form-select" name="town_id" id="reg-town" onchange="loadStreetsReg(this.value)">
                <option value="">— vyberte město —</option>
                @foreach($towns as $town)
                    <option value="{{ $town->id }}" {{ old('town_id') == $town->id ? 'selected' : '' }}>
                        {{ $town->town }}{{ $town->quarter ? ' - ' . $town->quarter : '' }}, {{ $town->zip_code }}
                    </option>
                @endforeach
            </select>
            @error('town_id') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="reg-street">Ulice <span style="color:#c0392b">*</span></label>
            <select class="m-form-select" name="street_id" id="reg-street">
                <option value="">— vyberte ulici —</option>
            </select>
            @error('street_id') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="reg-street-number">Číslo popisné <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="text" name="street_number" id="reg-street-number"
                   value="{{ old('street_number') }}" maxlength="15"
                   pattern="([eE][vV]\.?\s*[čČ]\.?\s*)?\d[\dA-Za-z\/\- ]*"
                   title="Začíná číslicí nebo &quot;ev. č.&quot;; povolené jsou číslice, písmena, lomítko, pomlčka (např. 123, 123/4a, ev. č. 503)"
                   placeholder="123, 123/4a nebo ev. č. 503"
                   style="max-width:160px">
            @error('street_number') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div></div>
    </div>
</div>

<div class="m-card" style="margin-bottom:16px">
    <div class="m-card-title">Doplňující informace</div>
    <div class="m-form-group">
        <label class="m-form-label" for="reg-comment">Poznámka</label>
        <textarea class="m-form-input" id="reg-comment" name="comment" rows="3" maxlength="250">{{ old('comment') }}</textarea>
    </div>
</div>

<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Odeslat registraci</button>
    <a class="m-btn" href="{{ route('login') }}">&larr; Zpět na přihlášení</a>
</div>
</form>
</div>

<script>
function loadStreetsReg(townId, selectedId) {
    if (!townId) return;
    fetch('{{ url('streets/by-town-public') }}/' + townId)
        .then(r => r.json())
        .then(streets => {
            const sel = document.getElementById('reg-street');
            sel.innerHTML = '<option value="">— vyberte ulici —</option>';
            streets.forEach(s => {
                const opt = document.createElement('option');
                opt.value = s.id;
                opt.textContent = s.street;
                if (selectedId && s.id == selectedId) opt.selected = true;
                sel.appendChild(opt);
            });
        });
}
document.addEventListener('DOMContentLoaded', function() {
    const townId = document.getElementById('reg-town').value;
    if (townId) loadStreetsReg(townId, '{{ old('street_id') }}');
});
async function loadFromAresReg() {
    const ico    = document.getElementById('reg-ico').value.trim();
    const status = document.getElementById('reg-ares-status');
    if (!ico || ico.length !== 8) { status.textContent = '⚠ Zadejte 8místné IČO.'; status.style.color = 'orange'; return; }
    status.textContent = '⏳ Načítám...'; status.style.color = '#666';
    try {
        const res = await fetch('{{ url('ares/lookup-public') }}/' + ico);
        const data = await res.json();
        if (data.error) { status.textContent = '✗ ' + data.error; status.style.color = 'red'; return; }
        if (data.nazev) { document.getElementById('reg-name').value = data.nazev; document.getElementById('reg-surname').value = ''; document.getElementById('reg-surname').placeholder = '(firma)'; }
        if (data.dic)    document.getElementById('reg-dic').value = data.dic;
        if (data.town_id) { document.getElementById('reg-town').value = data.town_id; loadStreetsReg(data.town_id, data.street_id); }
        if (data.cislo) document.getElementById('reg-street-number').value = data.cislo;
        const adresa = document.getElementById('reg-ares-adresa');
        if (adresa && (data.mesto || data.ulice)) {
            let txt = '📍 ARES: ' + data.ulice + ', ' + data.mesto + ' ' + data.psc;
            if (data.town_id) txt += ' ✓ město nalezeno'; else txt += ' ⚠ město nenalezeno v DB';
            if (data.street_id) txt += ', ulice nalezena'; else if (data.ulice_nazev) txt += ', ⚠ ulice nenalezena v DB';
            adresa.textContent = txt; adresa.style.display = 'block';
        }
        status.textContent = '✓ Data načtena z ARES'; status.style.color = 'green';
    } catch(e) { status.textContent = '✗ Chyba připojení k ARES'; status.style.color = 'red'; }
}
</script>
<script>
var security_password_length = {{ (int) \App\Models\Setting::get('security_password_length', 8) }};
var security_password_level  = {{ (int) \App\Models\Setting::get('security_password_level', 3) }};
</script>
@endsection
