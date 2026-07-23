@extends('layouts.app')
@section('title', 'Upravit člena')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('members.index') }}">Členové</a> &raquo;
    <a href="{{ route('members.show', $member->id) }}">{{ $member->name }}</a> &raquo;
    Upravit
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Upravit člena: {{ $member->name }}</h2></div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

<form method="POST" action="{{ route('members.update', $member->id) }}">
@csrf
@method('PUT')

<div class="m-card" style="margin-bottom:16px">
    <div class="m-card-title">Základní informace</div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="name-edit">Název / Jméno <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="text" id="name-edit" name="name"
                   value="{{ old('name', $member->name) }}" maxlength="100">
            @error('name') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="type">Typ člena <span style="color:#c0392b">*</span></label>
            <select class="m-form-select" id="type" name="type">
                @foreach($types as $value => $label)
                    <option value="{{ $value }}" @selected(old('type', $member->type) == $value)>{{ $label }}</option>
                @endforeach
            </select>
            @error('type') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="entrance_date">Datum vstupu</label>
            <input class="m-form-input" type="date" id="entrance_date" name="entrance_date"
                   value="{{ old('entrance_date', $member->entrance_date !== '0000-00-00' ? $member->entrance_date : '') }}">
            @error('entrance_date') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="leaving_date">Datum odchodu</label>
            @php
                $leavingVal = old('leaving_date', $member->leaving_date);
                $leavingVal = in_array($leavingVal, ['0000-00-00', '9999-12-31']) ? '' : $leavingVal;
            @endphp
            <input class="m-form-input" type="date" id="leaving_date" name="leaving_date" value="{{ $leavingVal }}">
            @error('leaving_date') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="ico-edit">IČO</label>
            <div style="display:flex;gap:8px;align-items:center">
                <input class="m-form-input" type="text" id="ico-edit" name="organization_identifier"
                       value="{{ old('organization_identifier', $member->organization_identifier) }}" maxlength="8"
                       autocomplete="off" inputmode="numeric" pattern="[0-9]*">
                <button class="m-btn" type="button" onclick="loadFromAresEdit()" style="white-space:nowrap">🔍 ARES</button>
            </div>
            <span id="ares-status-edit" class="m-form-hint"></span>
            @error('organization_identifier') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="dic-edit">DIČ</label>
            <input class="m-form-input" type="text" id="dic-edit" name="vat_organization_identifier"
                   value="{{ old('vat_organization_identifier', $member->vat_organization_identifier) }}" maxlength="30"
                   autocomplete="off" placeholder="CZ12345678">
            @error('vat_organization_identifier') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="m-form-group">
        <label class="m-form-label" for="comment">Poznámka</label>
        <textarea class="m-form-input" id="comment" name="comment" rows="3" maxlength="250">{{ old('comment', $member->comment) }}</textarea>
        @error('comment') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
</div>

<div class="m-card" style="margin-bottom:16px">
    <div class="m-card-title">Adresa</div>
    <div id="ares-adresa-info-edit" class="m-alert m-alert-success" style="display:none;margin-bottom:12px"></div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="town-edit">Město</label>
            <select class="m-form-select" id="town-edit" name="town_id" onchange="loadStreetsEdit(this.value)">
                <option value="">— vyberte město —</option>
                @foreach($towns as $town)
                    <option value="{{ $town->id }}"
                        @selected(old('town_id', $member->addressPoint?->town_id) == $town->id)>
                        {{ $town->town }}{{ $town->quarter ? ' - ' . $town->quarter : '' }}, {{ $town->zip_code }}
                    </option>
                @endforeach
            </select>
            @error('town_id') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="street-edit">Ulice</label>
            <select class="m-form-select" id="street-edit" name="street_id">
                <option value="">— vyberte ulici —</option>
                @foreach($streets as $street)
                    <option value="{{ $street->id }}"
                        {{ old('street_id', $member->addressPoint?->street_id) == $street->id ? 'selected' : '' }}>
                        {{ $street->street }}
                    </option>
                @endforeach
            </select>
            @error('street_id') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="street-number-edit">Číslo popisné</label>
            <input class="m-form-input" type="text" id="street-number-edit" name="street_number"
                   value="{{ old('street_number', $member->addressPoint?->street_number) }}" maxlength="15"
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
    <div class="m-card-title">Další informace</div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="locked">Přístup do systému</label>
            <select class="m-form-select" id="locked" name="locked">
                <option value="0" @selected(!old('locked', $member->locked))>Odemčen</option>
                <option value="1" @selected(old('locked', $member->locked))>Zamčen</option>
            </select>
            @error('locked') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="registration">Registrace / Smlouva</label>
            <select class="m-form-select" id="registration" name="registration">
                <option value="1" @selected(old('registration', $member->registration))>Ano</option>
                <option value="0" @selected(!old('registration', $member->registration))>Ne</option>
            </select>
            @error('registration') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="m-form-group">
        <label class="m-form-label">Příjem oznámení</label>
        <div class="m-form-hint" style="margin-bottom:6px">Pokud člen nechce dostávat upozornění daným kanálem, odškrtni.</div>
        <div style="display:flex;gap:18px;flex-wrap:wrap">
            <label style="display:inline-flex;gap:6px;align-items:center;cursor:pointer">
                <input type="hidden" name="notification_by_redirection" value="0">
                <input type="checkbox" name="notification_by_redirection" value="1"
                       @checked(old('notification_by_redirection', $member->notification_by_redirection))>
                Přesměrování (redirect)
            </label>
            <label style="display:inline-flex;gap:6px;align-items:center;cursor:pointer">
                <input type="hidden" name="notification_by_email" value="0">
                <input type="checkbox" name="notification_by_email" value="1"
                       @checked(old('notification_by_email', $member->notification_by_email))>
                E-mail
            </label>
            <label style="display:inline-flex;gap:6px;align-items:center;cursor:pointer">
                <input type="hidden" name="notification_by_sms" value="0">
                <input type="checkbox" name="notification_by_sms" value="1"
                       @checked(old('notification_by_sms', $member->notification_by_sms))>
                SMS
            </label>
        </div>
    </div>
    @if($canEditQos && $speedClasses->count() > 0)
    <div class="m-form-group">
        <label class="m-form-label" for="speed_class_id">Třída rychlosti (QoS)</label>
        <select class="m-form-select" id="speed_class_id" name="speed_class_id" style="max-width:420px">
            <option value="">— žádná —</option>
            @foreach($speedClasses as $sc)
                <option value="{{ $sc->id }}"
                    {{ old('speed_class_id', $member->speed_class_id ?? $defaultSpeedClass?->id) == $sc->id ? 'selected' : '' }}>
                    {{ $sc->name }}
                    (max: {{ \App\Models\SpeedClass::formatPair($sc->d_ceil, $sc->u_ceil) }},
                     min: {{ \App\Models\SpeedClass::formatPair($sc->d_rate, $sc->u_rate) }})
                </option>
            @endforeach
        </select>
        @if($defaultSpeedClass)
            <div class="m-form-hint">Výchozí: {{ $defaultSpeedClass->name }}</div>
        @endif
        @error('speed_class_id') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    @endif
</div>

<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Uložit</button>
    <a class="m-btn" href="{{ route('members.show', $member->id) }}">Zrušit</a>
</div>
</form>

<script>
// Přidá option do <select> města, pokud tam ještě není (nově založené z ARES).
function ensureTownOption(selectId, id, label) {
    const sel = document.getElementById(selectId);
    if (!sel || !id) return;
    if (!sel.querySelector('option[value="' + id + '"]')) {
        const opt = document.createElement('option');
        opt.value = id;
        opt.textContent = label || ('#' + id);
        sel.appendChild(opt);
    }
}
function loadStreetsEdit(townId, selectedId) {
    if (!townId) return;
    fetch('{{ url('streets/by-town') }}/' + townId)
        .then(r => r.json())
        .then(streets => {
            const sel = document.getElementById('street-edit');
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
    const townId  = document.getElementById('town-edit')?.value;
    const streetId = '{{ old('street_id', $member->addressPoint?->street_id) }}';
    if (townId) loadStreetsEdit(townId, streetId);
});

async function loadFromAresEdit() {
    const ico    = document.getElementById('ico-edit').value.trim();
    const status = document.getElementById('ares-status-edit');

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

        if (data.nazev) document.getElementById('name-edit').value = data.nazev;
        if (data.dic)   document.getElementById('dic-edit').value  = data.dic;
        if (data.town_id) {
            ensureTownOption('town-edit', data.town_id, data.town_name);
            document.getElementById('town-edit').value = data.town_id;
            loadStreetsEdit(data.town_id, data.street_id);
        }
        if (data.cislo) document.getElementById('street-number-edit').value = data.cislo;

        const adresaInfo = document.getElementById('ares-adresa-info-edit');
        if (adresaInfo && (data.mesto || data.ulice)) {
            let adresaText = '📍 ARES adresa: ' + data.ulice + ', ' + data.mesto + ' ' + data.psc;
            if (data.town_created)     adresaText += ' ✓ město automaticky přidáno';
            else if (data.town_id)     adresaText += ' ✓ město nalezeno';
            else                       adresaText += ' ⚠ město nenalezeno';
            if (data.street_created)   adresaText += ', ulice automaticky přidána';
            else if (data.street_id)   adresaText += ', ulice nalezena';
            else if (data.ulice_nazev) adresaText += ', ⚠ ulice nenalezena';
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
