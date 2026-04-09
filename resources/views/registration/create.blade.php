@extends('layouts.app')
@section('title', 'Registrace')
@section('menu') @endsection
@section('breadcrumbs') @endsection
@section('content')
<div style="max-width:700px; margin:2em auto;">
    <h2>Registrace</h2>
    <p>Vyplňte registrační formulář. Po odeslání vás bude kontaktovat správce sítě.</p>

    @if($errors->any())
        <div style="background:#f8d7da; border:1px solid #f5c6cb; padding:10px; margin-bottom:1em; border-radius:4px;">
            <ul style="margin:0; padding-left:1.2em;">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('registration.store') }}">
        @csrf

        {{-- TYP --}}
        <h3 style="margin-top:1em;">Typ registrace</h3>
        <div style="display:flex; gap:20px; margin:1em 0 1.5em 0;">
            <label style="flex:1; cursor:pointer;">
                <input type="radio" name="registration_type" value="18"
                    {{ old('registration_type', '18') == '18' ? 'checked' : '' }}
                    style="display:none;" class="reg-type-radio">
                <div class="reg-type-card" style="border:2px solid #ddd; border-radius:8px; padding:20px; text-align:center; transition:border-color 0.2s;">
                    <div style="font-size:2em; margin-bottom:8px;">🌐</div>
                    <div style="font-weight:bold; font-size:1.1em; color:#333;">Zákazník</div>
                    <div style="color:#666; font-size:0.9em; margin-top:4px;">Internet domů / do firmy</div>
                </div>
            </label>
            <label style="flex:1; cursor:pointer;">
                <input type="radio" name="registration_type" value="17"
                    {{ old('registration_type') == '17' ? 'checked' : '' }}
                    style="display:none;" class="reg-type-radio">
                <div class="reg-type-card" style="border:2px solid #ddd; border-radius:8px; padding:20px; text-align:center; transition:border-color 0.2s;">
                    <div style="font-size:2em; margin-bottom:8px;">🤝</div>
                    <div style="font-weight:bold; font-size:1.1em; color:#333;">Člen spolku</div>
                    <div style="color:#666; font-size:0.9em; margin-top:4px;">Chci se stát členem PVfree.net, z.s.</div>
                </div>
            </label>
        </div>
        <style>
        .reg-type-radio:checked + .reg-type-card {
            border-color: #c00;
            background: #fff5f5;
        }
        .reg-type-card:hover {
            border-color: #999;
        }
        </style>

        {{-- ZÁKLADNÍ INFO --}}
        <h3 style="margin-top:1.5em;">Základní informace</h3>
        <table class="extended" cellspacing="0">
            <tr>
                <th><label for="reg-name">Jméno <span style="color:red">*</span></label></th>
                <td>
                    <input type="text" name="name" id="reg-name" value="{{ old('name') }}" style="width:200px" maxlength="100">
                    @error('name') <span style="color:red">{{ $message }}</span> @enderror
                </td>
            </tr>
            <tr>
                <th><label for="reg-surname">Příjmení</label></th>
                <td>
                    <input type="text" name="surname" id="reg-surname" value="{{ old('surname') }}" style="width:200px" maxlength="100">
                </td>
            </tr>
            <tr>
                <th><label for="reg-birthday">Datum narození <span style="color:red">*</span></label></th>
                <td>
                    <input type="date" id="reg-birthday" name="birthday" value="{{ old('birthday') }}" style="width:160px">
                    @error('birthday') <span style="color:red">{{ $message }}</span> @enderror
                </td>
            </tr>
            <tr>
                <th><label for="reg-ico">IČO</label></th>
                <td>
                    <input type="text" name="organization_identifier" id="reg-ico"
                           value="{{ old('organization_identifier') }}"
                           style="width:150px" maxlength="8" placeholder="12345678">
                    <button type="button" onclick="loadFromAresReg()" style="margin-left:8px;">🔍 Načíst z ARES</button>
                    <span id="reg-ares-status" style="margin-left:8px; font-size:0.9em;"></span>
                </td>
            </tr>
            <tr>
                <th><label for="reg-dic">DIČ</label></th>
                <td>
                    <input type="text" name="vat_organization_identifier" id="reg-dic"
                           value="{{ old('vat_organization_identifier') }}" style="width:150px" maxlength="20">
                </td>
            </tr>
        </table>

        {{-- PŘIHLAŠOVACÍ ÚDAJE --}}
        <h3 style="margin-top:1.5em;">Přihlašovací údaje</h3>
        <table class="extended" cellspacing="0">
            <tr>
                <th><label for="reg-login">Login <span style="color:red">*</span></label></th>
                <td>
                    <input type="text" id="reg-login" name="login" value="{{ old('login') }}"
                           style="width:200px" minlength="5" maxlength="20" autocomplete="off">
                    <small style="color:#888;">5–20 znaků, unikátní</small>
                    @error('login') <span style="color:red">{{ $message }}</span> @enderror
                </td>
            </tr>
            <tr>
                <th><label for="reg-password">Heslo <span style="color:red">*</span></label></th>
                <td>
                    <input type="password" id="reg-password" name="password" style="width:200px" autocomplete="new-password">
                    @error('password') <span style="color:red">{{ $message }}</span> @enderror
                </td>
            </tr>
            <tr>
                <th><label for="reg-password-confirm">Heslo znovu <span style="color:red">*</span></label></th>
                <td>
                    <input type="password" id="reg-password-confirm" name="password_confirmation" style="width:200px">
                </td>
            </tr>
        </table>

        {{-- KONTAKTY --}}
        <h3 style="margin-top:1.5em;">Kontaktní informace</h3>
        <table class="extended" cellspacing="0">
            <tr>
                <th><label for="reg-phone">Telefon <span style="color:red">*</span></label></th>
                <td>
                    <input type="text" id="reg-phone" name="phone" value="{{ old('phone') }}"
                           style="width:180px" placeholder="+420123456789" maxlength="30">
                    @error('phone') <span style="color:red">{{ $message }}</span> @enderror
                </td>
            </tr>
            <tr>
                <th><label for="reg-email">E-mail <span style="color:red">*</span></label></th>
                <td>
                    <input type="text" id="reg-email" name="email" value="{{ old('email') }}" style="width:250px" maxlength="255">
                    @error('email') <span style="color:red">{{ $message }}</span> @enderror
                </td>
            </tr>
        </table>

        {{-- ADRESA --}}
        <h3 style="margin-top:1.5em;">Adresa připojení</h3>
        <div id="reg-ares-adresa" style="display:none; background:#e8f4e8; border:1px solid #4caf50; padding:6px 10px; margin-bottom:8px; border-radius:4px; font-size:0.9em;"></div>
        <table class="extended" cellspacing="0">
            <tr>
                <th><label for="reg-town">Město <span style="color:red">*</span></label></th>
                <td>
                    <select name="town_id" id="reg-town" onchange="loadStreetsReg(this.value)" style="width:280px">
                        <option value="">— vyberte město —</option>
                        @foreach($towns as $town)
                            <option value="{{ $town->id }}" {{ old('town_id') == $town->id ? 'selected' : '' }}>
                                {{ $town->town }}{{ $town->quarter ? ' - ' . $town->quarter : '' }}, {{ $town->zip_code }}
                            </option>
                        @endforeach
                    </select>
                    @error('town_id') <span style="color:red">{{ $message }}</span> @enderror
                </td>
            </tr>
            <tr>
                <th><label for="reg-street">Ulice <span style="color:red">*</span></label></th>
                <td>
                    <select name="street_id" id="reg-street" style="width:280px">
                        <option value="">— vyberte ulici —</option>
                    </select>
                    @error('street_id') <span style="color:red">{{ $message }}</span> @enderror
                </td>
            </tr>
            <tr>
                <th><label for="reg-street-number">Číslo popisné <span style="color:red">*</span></label></th>
                <td>
                    <input type="text" name="street_number" id="reg-street-number"
                           value="{{ old('street_number') }}" style="width:100px" maxlength="50">
                    @error('street_number') <span style="color:red">{{ $message }}</span> @enderror
                </td>
            </tr>
        </table>

        {{-- POZNÁMKA --}}
        <h3 style="margin-top:1.5em;">Doplňující informace</h3>
        <table class="extended" cellspacing="0">
            <tr>
                <th><label for="reg-comment">Poznámka</label></th>
                <td>
                    <textarea id="reg-comment" name="comment" rows="3" style="width:400px" maxlength="250">{{ old('comment') }}</textarea>
                </td>
            </tr>
        </table>

        <div style="margin-top:1.5em;">
            <button type="submit" style="padding:8px 20px; font-size:1em;">Odeslat registraci</button>
            <a href="{{ route('login') }}" style="margin-left:1.5em;">← Zpět na přihlášení</a>
        </div>
    </form>
</div>

<script>
function loadStreetsReg(townId, selectedId) {
    if (!townId) return;
    fetch('/new/streets/by-town-public/' + townId)
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
    if (!ico || ico.length !== 8) {
        status.textContent = '⚠ Zadejte 8místné IČO.';
        status.style.color = 'orange';
        return;
    }
    status.textContent = '⏳ Načítám...';
    status.style.color = '#666';
    try {
        const res  = await fetch('/new/ares/lookup-public/' + ico);
        const data = await res.json();
        if (data.error) {
            status.textContent = '✗ ' + data.error;
            status.style.color = 'red';
            return;
        }
        if (data.nazev) {
            document.getElementById('reg-name').value = data.nazev;
            document.getElementById('reg-surname').value = '';
            document.getElementById('reg-surname').placeholder = '(firma — nevyplňovat)';
        }
        if (data.dic)    document.getElementById('reg-dic').value = data.dic;
        if (data.town_id) {
            document.getElementById('reg-town').value = data.town_id;
            loadStreetsReg(data.town_id, data.street_id);
        }
        if (data.cislo)  document.getElementById('reg-street-number').value = data.cislo;
        const adresa = document.getElementById('reg-ares-adresa');
        if (adresa && (data.mesto || data.ulice)) {
            let txt = '📍 ARES: ' + data.ulice + ', ' + data.mesto + ' ' + data.psc;
            if (data.town_id)          txt += ' ✓ město nalezeno';
            else                       txt += ' ⚠ město nenalezeno v DB';
            if (data.street_id)        txt += ', ulice nalezena';
            else if (data.ulice_nazev) txt += ', ⚠ ulice nenalezena v DB';
            adresa.textContent  = txt;
            adresa.style.display = 'block';
        }
        status.textContent = '✓ Data načtena z ARES';
        status.style.color = 'green';
    } catch(e) {
        status.textContent = '✗ Chyba připojení k ARES';
        status.style.color = 'red';
    }
}
</script>
@endsection
