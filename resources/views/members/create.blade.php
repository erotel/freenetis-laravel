@extends('layouts.app')
@section('title', 'Přidat člena')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
    <div id="breadcrumbs"><a href="{{ route('members.index') }}">Členové</a> » Přidat nového člena</div>
@endsection
@section('content')
<h2>Přidat nového člena</h2>

@if($errors->any())
    <div style="color:red; margin-bottom:1em;">
        <ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

<form method="POST" action="{{ route('members.store') }}">
    @csrf

    {{-- ZÁKLADNÍ INFORMACE --}}
    <h3>Základní informace</h3>
    <table class="extended" cellspacing="0">
        <tr>
            <th><label for="name">Jméno <span style="color:red">*</span></label></th>
            <td>
                <input type="text" id="name" name="name" value="{{ old('name') }}" maxlength="30" style="width:200px">
                @error('name') <span style="color:red">{{ $message }}</span> @enderror
            </td>
        </tr>
        <tr>
            <th><label for="surname" id="surname-label">Příjmení <span id="surname-required" style="color:red">*</span></label></th>
            <td>
                <input type="text" id="surname" name="surname" value="{{ old('surname') }}" maxlength="60" style="width:200px">
                @error('surname') <span style="color:red">{{ $message }}</span> @enderror
            </td>
        </tr>
        <tr>
            <th><label for="type">Typ člena <span style="color:red">*</span></label></th>
            <td>
                <select id="type" name="type">
                    <option value="17" {{ old('type', 18) == 17 ? 'selected' : '' }}>Čekající člen</option>
                    <option value="18" {{ old('type', 18) == 18 ? 'selected' : '' }}>Čekající zákazník</option>
                </select>
                @error('type') <span style="color:red">{{ $message }}</span> @enderror
            </td>
        </tr>
        <tr>
            <th><label for="entrance_date">Datum vstupu <span style="color:red">*</span></label></th>
            <td>
                <input type="date" id="entrance_date" name="entrance_date"
                       value="{{ old('entrance_date', date('Y-m-d')) }}" style="width:150px">
                @error('entrance_date') <span style="color:red">{{ $message }}</span> @enderror
            </td>
        </tr>
        <tr>
            <th><label for="birthday">Datum narození <span style="color:red">*</span></label></th>
            <td>
                <input type="date" id="birthday" name="birthday" value="{{ old('birthday') }}" style="width:150px">
            </td>
        </tr>
        <tr>
            <th><label for="ico">IČO</label></th>
            <td>
                <input type="text" id="ico" name="organization_identifier"
                       value="{{ old('organization_identifier') }}" maxlength="8"
                       placeholder="12345678" style="width:120px">
                <button type="button" onclick="loadFromAres()" style="margin-left:8px;">🔍 Načíst z ARES</button>
                <span id="ares-status" style="margin-left:8px; font-size:0.9em;"></span>
            </td>
        </tr>
        <tr>
            <th><label for="vat_organization_identifier">DIČ</label></th>
            <td>
                <input type="text" id="vat_organization_identifier" name="vat_organization_identifier"
                       value="{{ old('vat_organization_identifier') }}" maxlength="30" style="width:150px">
            </td>
        </tr>
        <tr>
            <th><label for="comment">Poznámka</label></th>
            <td>
                <textarea id="comment" name="comment" rows="3" maxlength="250"
                          style="width:300px">{{ old('comment') }}</textarea>
            </td>
        </tr>
    </table>

    {{-- PŘIHLAŠOVACÍ ÚDAJE --}}
    <h3 style="margin-top:1.5em;">Přihlašovací údaje</h3>
    <table class="extended" cellspacing="0">
        <tr>
            <th><label for="login">Login <span style="color:red">*</span></label></th>
            <td>
                <input type="text" id="login" name="login" value="{{ old('login') }}"
                       minlength="5" maxlength="50" autocomplete="off" style="width:200px">
                <small style="color:#888;">5–50 znaků, unikátní</small>
                @error('login') <span style="color:red">{{ $message }}</span> @enderror
            </td>
        </tr>
        <tr>
            <th><label for="password">Heslo <span style="color:red">*</span></label></th>
            <td>
                <input type="password" id="password" name="password" autocomplete="new-password" style="width:200px">
                @error('password') <span style="color:red">{{ $message }}</span> @enderror
            </td>
        </tr>
        <tr>
            <th><label for="password_confirmation">Heslo znovu <span style="color:red">*</span></label></th>
            <td>
                <input type="password" id="password_confirmation" name="password_confirmation"
                       autocomplete="new-password" style="width:200px">
            </td>
        </tr>
    </table>

    {{-- KONTAKTNÍ INFORMACE --}}
    <h3 style="margin-top:1.5em;">Kontaktní informace</h3>
    <table class="extended" cellspacing="0">
        <tr>
            <th><label for="phone">Telefon <span style="color:red">*</span></label></th>
            <td>
                <input type="text" id="phone" name="phone" value="{{ old('phone') }}"
                       maxlength="40" placeholder="+420123456789" style="width:180px">
                @error('phone') <span style="color:red">{{ $message }}</span> @enderror
            </td>
        </tr>
        <tr>
            <th><label for="email">E-mail <span style="color:red">*</span></label></th>
            <td>
                <input type="text" id="email" name="email" value="{{ old('email') }}"
                       maxlength="255" style="width:250px">
                @error('email') <span style="color:red">{{ $message }}</span> @enderror
            </td>
        </tr>
    </table>

    {{-- ADRESA --}}
    <h3 style="margin-top:1.5em;">Adresa</h3>
    <div id="ares-adresa-info" style="display:none; background:#e8f4e8; border:1px solid #4caf50; padding:6px 10px; margin-bottom:8px; border-radius:4px; font-size:0.9em;"></div>
    <table class="extended" cellspacing="0">
        <tr>
            <th><label for="town_id">Město <span style="color:red">*</span></label></th>
            <td>
                <select id="town_id" name="town_id" onchange="loadStreets(this.value)">
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
            <th><label for="street_id">Ulice <span style="color:red">*</span></label></th>
            <td>
                <select id="street_id" name="street_id">
                    <option value="">— vyberte ulici —</option>
                </select>
                @error('street_id') <span style="color:red">{{ $message }}</span> @enderror
            </td>
        </tr>
        <tr>
            <th><label for="street_number">Číslo popisné <span style="color:red">*</span></label></th>
            <td>
                <input type="text" id="street_number" name="street_number"
                       value="{{ old('street_number') }}" maxlength="50" style="width:100px">
                @error('street_number') <span style="color:red">{{ $message }}</span> @enderror
            </td>
        </tr>
    </table>

    <div style="margin-top:1.5em;">
        <button type="submit">Uložit člena</button>
        <a href="{{ route('members.index') }}" style="margin-left:1em;">Zrušit</a>
    </div>
</form>

<script>
function loadStreets(townId, selectedId) {
    const sel = document.getElementById('street_id');
    sel.innerHTML = '<option value="">— vyberte ulici —</option>';
    if (!townId) return;
    fetch('/new/streets/by-town/' + townId)
        .then(r => r.json())
        .then(streets => {
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
    const townId = document.getElementById('town_id').value;
    const streetId = '{{ old('street_id') }}';
    if (townId) loadStreets(townId, streetId);
});

async function loadFromAres() {
    const ico    = document.getElementById('ico').value.trim();
    const status = document.getElementById('ares-status');

    if (!ico || ico.length !== 8) {
        status.textContent = '⚠ Zadejte 8místné IČO.';
        status.style.color = 'orange';
        return;
    }

    status.textContent = '⏳ Načítám...';
    status.style.color = '#666';

    try {
        const res  = await fetch('/new/ares/lookup/' + ico);
        const data = await res.json();

        if (data.error) {
            status.textContent = '✗ ' + data.error;
            status.style.color = 'red';
            return;
        }

        if (data.nazev) {
            document.querySelector('input[name="name"]').value = data.nazev;
            // Firma z ARES — příjmení není potřeba
            document.getElementById('surname').value = '';
            document.getElementById('surname').removeAttribute('required');
            document.getElementById('surname-required').style.display = 'none';
            document.getElementById('surname').placeholder = '(firma — nevyplňovat)';
            document.getElementById('surname').style.color = '#999';
        }
        if (data.dic) {
            document.querySelector('input[name="vat_organization_identifier"]').value = data.dic;
        }

        // Vyplnit město a ulici z dropdownů
        if (data.town_id) {
            document.getElementById('town_id').value = data.town_id;
            loadStreets(data.town_id, data.street_id);
        }

        // Vyplnit číslo popisné
        if (data.cislo) {
            document.querySelector('input[name="street_number"]').value = data.cislo;
        }

        // Informace o adrese
        const adresaInfo = document.getElementById('ares-adresa-info');
        if (adresaInfo && (data.mesto || data.ulice)) {
            let adresaText = '📍 ARES adresa: ' + data.ulice + ', ' + data.mesto + ' ' + data.psc;
            if (data.town_id)         adresaText += ' ✓ město nalezeno';
            else                      adresaText += ' ⚠ město nenalezeno v DB';
            if (data.street_id)       adresaText += ', ulice nalezena';
            else if (data.ulice_nazev) adresaText += ', ⚠ ulice nenalezena v DB';
            adresaInfo.textContent  = adresaText;
            adresaInfo.style.display = 'block';
        }

        status.textContent = '✓ Data načtena z ARES';
        status.style.color = 'green';

    } catch (e) {
        status.textContent = '✗ Chyba připojení k ARES';
        status.style.color = 'red';
    }
}
</script>
@endsection
