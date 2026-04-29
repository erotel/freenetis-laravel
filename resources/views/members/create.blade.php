@extends('layouts.app')
@section('title', 'Přidat člena')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs"><a href="{{ route('members.index') }}">Členové</a> &raquo; Přidat nového člena</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Přidat nového člena</h2></div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

<form method="POST" action="{{ route('members.store') }}">
@csrf

<div class="m-card" style="margin-bottom:16px">
    <div class="m-card-title">Základní informace</div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="name">Jméno / Název <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="text" id="name" name="name" value="{{ old('name') }}" maxlength="30">
            @error('name') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="surname" id="surname-label">Příjmení <span id="surname-required" style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="text" id="surname" name="surname" value="{{ old('surname') }}" maxlength="60">
            @error('surname') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="type">Typ člena <span style="color:#c0392b">*</span></label>
            <select class="m-form-select" id="type" name="type">
                <option value="17" {{ old('type', 18) == 17 ? 'selected' : '' }}>Čekající člen</option>
                <option value="18" {{ old('type', 18) == 18 ? 'selected' : '' }}>Čekající zákazník</option>
            </select>
            @error('type') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="entrance_date">Datum vstupu <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="date" id="entrance_date" name="entrance_date"
                   value="{{ old('entrance_date', date('Y-m-d')) }}">
            @error('entrance_date') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="birthday">Datum narození <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="date" id="birthday" name="birthday" value="{{ old('birthday') }}">
        </div>
        <div></div>
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="ico">IČO</label>
            <div style="display:flex;gap:8px;align-items:center">
                <input class="m-form-input" type="text" id="ico" name="organization_identifier"
                       value="{{ old('organization_identifier') }}" maxlength="8" placeholder="12345678">
                <button class="m-btn" type="button" onclick="loadFromAres()" style="white-space:nowrap">🔍 ARES</button>
            </div>
            <span id="ares-status" class="m-form-hint"></span>
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="vat_organization_identifier">DIČ</label>
            <input class="m-form-input" type="text" id="vat_organization_identifier" name="vat_organization_identifier"
                   value="{{ old('vat_organization_identifier') }}" maxlength="30">
        </div>
    </div>
    <div class="m-form-group">
        <label class="m-form-label" for="comment">Poznámka</label>
        <textarea class="m-form-input" id="comment" name="comment" rows="3" maxlength="250">{{ old('comment') }}</textarea>
    </div>
    <div id="ares-adresa-info" class="m-alert m-alert-success" style="display:none;margin-top:8px"></div>
</div>

<div class="m-card" style="margin-bottom:16px">
    <div class="m-card-title">Přihlašovací údaje</div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="login">Login <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="text" id="login" name="login" value="{{ old('login') }}"
                   minlength="5" maxlength="50" autocomplete="off">
            <div class="m-form-hint">5–50 znaků, unikátní</div>
            @error('login') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div></div>
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="password">Heslo <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="password" id="password" name="password" autocomplete="new-password">
            @error('password') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="password_confirmation">Heslo znovu <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password">
        </div>
    </div>
</div>

<div class="m-card" style="margin-bottom:16px">
    <div class="m-card-title">Kontaktní informace</div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="phone">Telefon <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="text" id="phone" name="phone" value="{{ old('phone') }}"
                   maxlength="40" placeholder="+420123456789">
            @error('phone') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="email">E-mail <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="text" id="email" name="email" value="{{ old('email') }}" maxlength="255">
            @error('email') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
</div>

<div class="m-card" style="margin-bottom:16px">
    <div class="m-card-title">Adresa</div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="town_id">Město <span style="color:#c0392b">*</span></label>
            <select class="m-form-select" id="town_id" name="town_id" onchange="loadStreets(this.value)">
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
            <label class="m-form-label" for="street_id">Ulice <span style="color:#c0392b">*</span></label>
            <select class="m-form-select" id="street_id" name="street_id">
                <option value="">— vyberte ulici —</option>
            </select>
            @error('street_id') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="street_number">Číslo popisné <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="text" id="street_number" name="street_number"
                   value="{{ old('street_number') }}" maxlength="50" style="max-width:140px">
            @error('street_number') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div></div>
    </div>
</div>

<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Uložit člena</button>
    <a class="m-btn" href="{{ route('members.index') }}">Zrušit</a>
</div>
</form>

<script>
function loadStreets(townId, selectedId) {
    const sel = document.getElementById('street_id');
    sel.innerHTML = '<option value="">— vyberte ulici —</option>';
    if (!townId) return;
    fetch('{{ url('streets/by-town') }}/' + townId)
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
        const res  = await fetch('{{ url('ares/lookup') }}/' + ico);
        const data = await res.json();

        if (data.error) {
            status.textContent = '✗ ' + data.error;
            status.style.color = 'red';
            return;
        }

        if (data.nazev) {
            document.querySelector('input[name="name"]').value = data.nazev;
            document.getElementById('surname').value = '';
            document.getElementById('surname').removeAttribute('required');
            document.getElementById('surname-required').style.display = 'none';
            document.getElementById('surname').placeholder = '(firma — nevyplňovat)';
            document.getElementById('surname').style.color = '#999';
        }
        if (data.dic) {
            document.querySelector('input[name="vat_organization_identifier"]').value = data.dic;
        }
        if (data.town_id) {
            document.getElementById('town_id').value = data.town_id;
            loadStreets(data.town_id, data.street_id);
        }
        if (data.cislo) {
            document.querySelector('input[name="street_number"]').value = data.cislo;
        }

        const adresaInfo = document.getElementById('ares-adresa-info');
        if (adresaInfo && (data.mesto || data.ulice)) {
            let adresaText = '📍 ARES adresa: ' + data.ulice + ', ' + data.mesto + ' ' + data.psc;
            if (data.town_id)          adresaText += ' ✓ město nalezeno';
            else                       adresaText += ' ⚠ město nenalezeno v DB';
            if (data.street_id)        adresaText += ', ulice nalezena';
            else if (data.ulice_nazev) adresaText += ', ⚠ ulice nenalezena v DB';
            adresaInfo.textContent   = adresaText;
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
</div>
@endsection
