@extends('layouts.app')
@section('title', 'Nastavení')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">Nastavení</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Nastavení</h2></div>

{{-- Tabs --}}
<div style="display:flex;gap:4px;margin-bottom:20px;flex-wrap:wrap">
    @foreach(['banka' => 'Banka', 'email' => 'Email', 'finance' => 'Finance', 'system' => 'Systém', 'users' => 'Uživatelé', 'network' => 'Síť', 'sms' => 'SMS'] as $tabKey => $tabLabel)
    <a class="m-btn @if($activeTab === $tabKey) m-btn-primary @endif"
       href="{{ route('settings.index', ['tab' => $tabKey]) }}">{{ $tabLabel }}</a>
    @endforeach
</div>

@if($activeTab === 'banka')
<form method="POST" action="{{ route('settings.update') }}">
@csrf @method('PUT')

<div class="m-card" style="margin-bottom:16px">
    <div class="m-card-title">Přiřazení bankovních účtů k typům členů (import výpisů)</div>
    <p class="m-form-hint" style="margin-bottom:12px">
        Platby přicházející na nesprávný bankovní účet pro daný typ člena zůstanou nespárované.
    </p>
    <div style="overflow-x:auto">
    <table class="m-table">
        <thead>
            <tr>
                <th>Typ člena</th>
                <th>Bankovní účet pro příjem plateb</th>
                <th style="width:130px">Vystavovat faktury?</th>
            </tr>
        </thead>
        <tbody>
            @foreach($routing as $type => $rule)
            <tr>
                <td>{{ $rule['label'] }}</td>
                <td>
                    <select class="m-form-select" name="routing_{{ $type }}">
                        <option value="0">(bez omezení)</option>
                        @foreach($bankAccounts as $ba)
                        <option value="{{ $ba->id }}" {{ $rule['bank_account_id'] == $ba->id ? 'selected' : '' }}>
                            {{ $ba->name }} ({{ $ba->full_account_number }})
                        </option>
                        @endforeach
                    </select>
                </td>
                <td style="text-align:center">
                    <input type="checkbox" name="payment_purpose_{{ $type }}" value="1"
                        {{ $rule['payment_purpose'] == 1 ? 'checked' : '' }}>
                </td>
            </tr>
            @endforeach
            <tr>
                <td>Výchozí účet pro import<br><span class="m-form-hint">použit když typ nemá pravidlo</span></td>
                <td>
                    <select class="m-form-select" name="default_bank_account_id">
                        <option value="0">(neurčen)</option>
                        @foreach($bankAccounts as $ba)
                        <option value="{{ $ba->id }}" {{ $defaultBaId == $ba->id ? 'selected' : '' }}>
                            {{ $ba->name }} ({{ $ba->full_account_number }})
                        </option>
                        @endforeach
                    </select>
                </td>
                <td></td>
            </tr>
        </tbody>
    </table>
    </div>
</div>

<div class="m-card" style="margin-bottom:16px;max-width:480px">
    <div class="m-card-title">Pohoda export</div>
    <div class="m-form-group">
        <label class="m-form-label">Email účetní (Pohoda export)</label>
        <input class="m-form-input" type="text" name="pohoda_accountant_email"
            value="{{ $pohodaEmail }}" placeholder="accountant@example.com">
        <div class="m-form-hint">Měsíční XML export faktur bude zasílán na tuto adresu.</div>
    </div>
</div>

<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Uložit nastavení banky</button>
</div>
</form>
@endif

@if($activeTab === 'email')
<form method="POST" action="{{ route('settings.update-email') }}">
@csrf @method('PUT')

<div class="m-card" style="margin-bottom:16px;max-width:520px">
    <div class="m-card-title">Nastavení odchozí pošty</div>
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
            <input type="checkbox" name="email_enabled" value="1"
                {{ ($emailSettings['email_enabled'] ?? '') == '1' ? 'checked' : '' }}>
            Povoleno
        </label>
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label">Driver</label>
            <select class="m-form-select" name="email_driver">
                @foreach(['smtp', 'sendmail'] as $d)
                <option value="{{ $d }}" {{ ($emailSettings['email_driver'] ?? '') === $d ? 'selected' : '' }}>{{ $d }}</option>
                @endforeach
            </select>
        </div>
        <div class="m-form-group">
            <label class="m-form-label">Šifrování</label>
            <select class="m-form-select" name="email_encryption">
                @foreach(['' => '(žádné)', 'tls' => 'TLS', 'ssl' => 'SSL'] as $val => $label)
                <option value="{{ $val }}" {{ ($emailSettings['email_encryption'] ?? '') === $val ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label">SMTP server</label>
            <input class="m-form-input" type="text" name="email_hostname" value="{{ $emailSettings['email_hostname'] ?? '' }}">
        </div>
        <div class="m-form-group">
            <label class="m-form-label">Port</label>
            <input class="m-form-input" type="text" name="email_port" value="{{ $emailSettings['email_port'] ?? '' }}" style="max-width:100px">
        </div>
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label">Uživatelské jméno</label>
            <input class="m-form-input" type="text" name="email_username" value="{{ $emailSettings['email_username'] ?? '' }}">
        </div>
        <div class="m-form-group">
            <label class="m-form-label">Heslo</label>
            <input class="m-form-input" type="password" name="email_password" value="{{ $emailSettings['email_password'] ?? '' }}">
        </div>
    </div>
    <div class="m-form-group">
        <label class="m-form-label">Odesílatel (from)</label>
        <input class="m-form-input" type="text" name="email_default_email" value="{{ $emailSettings['email_default_email'] ?? '' }}">
    </div>
</div>

<div class="m-card" style="margin-bottom:16px">
    <div class="m-card-title">Kopie emailů (BCC pravidla)</div>
    <p class="m-form-hint" style="margin-bottom:12px">
        Pokud předmět odeslaného emailu <strong>obsahuje</strong> zadaný text, odešle se kopie na zadanou adresu.
    </p>
    <div style="overflow-x:auto">
    <table class="m-table" id="bcc-rules">
        <thead>
            <tr>
                <th>Zpráva (volitelné)</th>
                <th>Předmět obsahuje</th>
                <th>BCC adresa</th>
                <th style="width:40px"></th>
            </tr>
        </thead>
        <tbody>
            @foreach($bccRules as $i => $rule)
            <tr>
                <td>
                    <select class="m-form-select" name="bcc_message_id[]" onchange="prefillSubject(this)">
                        <option value="">— vyberte zprávu —</option>
                        @foreach($messages as $msg)
                        <option value="{{ $msg->id }}" data-name="{{ $msg->name }}"
                            {{ ($rule['message_id'] ?? '') == $msg->id ? 'selected' : '' }}>
                            {{ $msg->name }}
                        </option>
                        @endforeach
                    </select>
                </td>
                <td>
                    <input class="m-form-input" type="text" name="bcc_subject_prefix[]"
                        value="{{ $rule['subject_prefix'] ?? '' }}" placeholder="např. Faktura ">
                </td>
                <td>
                    <input class="m-form-input" type="text" name="bcc_address[]"
                        value="{{ $rule['address'] ?? '' }}" placeholder="kopie@example.com">
                </td>
                <td>
                    <button type="button" style="background:none;border:none;cursor:pointer;color:#c0392b;font-size:16px"
                            onclick="this.closest('tr').remove()">✕</button>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    </div>
    <div style="margin-top:8px">
        <button class="m-btn" type="button" onclick="addBccRow()">+ Přidat pravidlo</button>
    </div>
</div>

<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Uložit nastavení emailu</button>
</div>
</form>

<script>
function prefillSubject(select) {
    const name = select.options[select.selectedIndex].dataset.name || '';
    const row = select.closest('tr');
    const prefixInput = row.querySelector('input[name="bcc_subject_prefix[]"]');
    if (prefixInput && prefixInput.value === '') prefixInput.value = name;
}
const messageOptions = `{!! collect($messages)->map(fn($m) => '<option value="' . $m->id . '" data-name="' . e($m->name) . '">' . e($m->name) . '</option>')->implode('') !!}`;
function addBccRow() {
    const tbody = document.querySelector('#bcc-rules tbody');
    const tr = document.createElement('tr');
    tr.innerHTML = '<td><select class="m-form-select" name="bcc_message_id[]" onchange="prefillSubject(this)"><option value="">— vyberte zprávu —</option>' + messageOptions + '</select></td>' +
        '<td><input class="m-form-input" type="text" name="bcc_subject_prefix[]" placeholder="např. Faktura "></td>' +
        '<td><input class="m-form-input" type="text" name="bcc_address[]" placeholder="kopie@example.com"></td>' +
        '<td><button type="button" style="background:none;border:none;cursor:pointer;color:#c0392b;font-size:16px" onclick="this.closest(\'tr\').remove()">✕</button></td>';
    tbody.appendChild(tr);
}
</script>
@endif

@if($activeTab === 'finance')
<form method="POST" action="{{ route('settings.update-finance') }}">
@csrf @method('PUT')

<div class="m-card" style="margin-bottom:16px;max-width:600px">
    <div class="m-card-title">Nastavení financí</div>
    <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:12px">
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
            <input type="checkbox" name="finance_enabled" value="1"
                {{ ($financeSettings['finance_enabled'] ?? '') == '1' ? 'checked' : '' }}>
            Finanční systém povolen
        </label>
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
            <input type="checkbox" name="deduct_fees_automatically_enabled" value="1"
                {{ ($financeSettings['deduct_fees_automatically_enabled'] ?? '') == '1' ? 'checked' : '' }}>
            Automatické strhávání poplatků
        </label>
    </div>
    <div class="m-form-group">
        <label class="m-form-label">Den strhávání poplatků</label>
        <input class="m-form-input" type="number" name="deduct_day"
            value="{{ $financeSettings['deduct_day'] ?? 1 }}" min="1" max="31" style="max-width:80px">
        <div class="m-form-hint">Den v měsíci (1–31), kdy se automaticky strhávají poplatky.</div>
    </div>
    <div class="m-form-group">
        <label class="m-form-label">Výchozí tarif — Zákazník (typ 2)</label>
        <select class="m-form-select" name="default_fee_member_type_2">
            <option value="">— nevybráno —</option>
            @foreach($feesForSelect as $fee)
            <option value="{{ $fee->id }}" {{ ($financeSettings['default_fee_member_type_2'] ?? '') == $fee->id ? 'selected' : '' }}>
                {{ $fee->name }} — {{ number_format($fee->fee, 2, ',', ' ') }} Kč
                (od {{ $fee->from }}{{ $fee->to ? ' do ' . $fee->to : ' ∞' }})
            </option>
            @endforeach
        </select>
        <div class="m-form-hint">Použije se pokud člen nemá individuální tarif.</div>
    </div>
    <div class="m-form-group">
        <label class="m-form-label">Výchozí tarif — Člen (typ 90)</label>
        <select class="m-form-select" name="default_fee_member_type_90">
            <option value="">— nevybráno —</option>
            @foreach($feesForSelect as $fee)
            <option value="{{ $fee->id }}" {{ ($financeSettings['default_fee_member_type_90'] ?? '') == $fee->id ? 'selected' : '' }}>
                {{ $fee->name }} — {{ number_format($fee->fee, 2, ',', ' ') }} Kč
                (od {{ $fee->from }}{{ $fee->to ? ' do ' . $fee->to : ' ∞' }})
            </option>
            @endforeach
        </select>
        <div class="m-form-hint">Použije se pokud člen nemá individuální tarif.</div>
    </div>
</div>

<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Uložit nastavení financí</button>
</div>
</form>
@endif

@if($activeTab === 'system')
<form method="POST" action="{{ route('settings.update-system') }}">
@csrf @method('PUT')

<div class="m-card" style="margin-bottom:16px;max-width:560px">
    <div class="m-card-title">Nastavení systému</div>

    <div class="m-form-group">
        <label class="m-form-label">Titulek stránky</label>
        <input class="m-form-input" type="text" name="title" value="{{ $systemSettings['title'] ?? '' }}">
    </div>

    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label">IČO organizace</label>
            <input class="m-form-input" type="text" name="ico" value="{{ $systemSettings['ico'] ?? '' }}" placeholder="12345678">
        </div>
        <div class="m-form-group">
            <label class="m-form-label">DIČ organizace</label>
            <input class="m-form-input" type="text" name="dic" value="{{ $systemSettings['dic'] ?? '' }}" placeholder="CZ12345678">
        </div>
    </div>

    <div class="m-form-group">
        <label class="m-form-label">Vypršení session (s)</label>
        <input class="m-form-input" type="number" name="session_expiration"
            value="{{ $systemSettings['session_expiration'] ?? 7200 }}" min="300" style="max-width:120px">
        <div class="m-form-hint">Doba nečinnosti v sekundách (výchozí: 7200 = 2 hodiny).</div>
    </div>

    <div class="m-form-group">
        <div class="m-form-label" style="margin-bottom:8px">Volby</div>
        <div style="display:flex;flex-direction:column;gap:10px">
            <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;font-size:13px">
                <input type="checkbox" name="self_registration" value="1"
                    style="margin-top:2px;flex-shrink:0"
                    {{ ($systemSettings['self_registration'] ?? '') == '1' ? 'checked' : '' }}>
                <span>
                    <strong>Samo-registrace</strong>
                    <div class="m-form-hint" style="margin-top:1px">Umožní nepřihlášeným uživatelům vyplnit registrační formulář.</div>
                </span>
            </label>
            <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;font-size:13px">
                <input type="checkbox" name="forgotten_password" value="1"
                    style="margin-top:2px;flex-shrink:0"
                    {{ ($systemSettings['forgotten_password'] ?? '') == '1' ? 'checked' : '' }}>
                <span>
                    <strong>Zapomenuté heslo</strong>
                    <div class="m-form-hint" style="margin-top:1px">Zobrazí odkaz pro reset hesla na přihlašovací stránce.</div>
                </span>
            </label>
        </div>
    </div>
</div>

<div class="m-card" style="margin-bottom:16px;max-width:560px">
    <div class="m-card-title">Kontaktní údaje sdružení <span style="font-weight:400;text-transform:none;letter-spacing:0;color:#bbb;font-size:11px">(pro PDF výpovědi smlouvy)</span></div>

    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label">Web (www)</label>
            <input class="m-form-input" type="text" name="association_www"
                value="{{ $systemSettings['association_www'] ?? '' }}" placeholder="www.pvfree.net">
        </div>
        <div class="m-form-group">
            <label class="m-form-label">E-mail</label>
            <input class="m-form-input" type="text" name="association_email"
                value="{{ $systemSettings['association_email'] ?? '' }}" placeholder="spravci@pvfree.net">
        </div>
    </div>

    <div class="m-form-group">
        <label class="m-form-label">Telefon</label>
        <input class="m-form-input" type="text" name="association_phone"
            value="{{ $systemSettings['association_phone'] ?? '' }}" placeholder="588 207 234" style="max-width:200px">
    </div>

    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label">Spolkový rejstřík — soud</label>
            <input class="m-form-input" type="text" name="association_court"
                value="{{ $systemSettings['association_court'] ?? '' }}" placeholder="Krajský soud v Brně">
        </div>
        <div class="m-form-group">
            <label class="m-form-label">Spolkový rejstřík — sp. značka</label>
            <input class="m-form-input" type="text" name="association_court_ref"
                value="{{ $systemSettings['association_court_ref'] ?? '' }}" placeholder="L 10341">
        </div>
    </div>
</div>

<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Uložit nastavení systému</button>
</div>
</form>
@endif

@if($activeTab === 'users')
<form method="POST" action="{{ route('settings.update-users') }}">
@csrf @method('PUT')

<div class="m-card" style="margin-bottom:16px;max-width:480px">
    <div class="m-card-title">Nastavení uživatelů</div>

    <div class="m-form-group">
        <label class="m-form-label">Minimální délka hesla</label>
        <div style="display:flex;align-items:center;gap:10px">
            <input class="m-form-input" type="number" name="security_password_length"
                value="{{ $usersSettings['security_password_length'] ?? 8 }}" min="4" max="32"
                style="max-width:80px">
            <span style="font-size:13px;color:#888">znaků</span>
        </div>
        <div class="m-form-hint">Výchozí: 8</div>
    </div>

    <div class="m-form-group">
        <label class="m-form-label">Minimální úroveň hesla</label>
        <select class="m-form-select" name="security_password_level" style="max-width:200px">
            @foreach([1 => 'Velmi slabé', 2 => 'Slabé', 3 => 'Dobré', 4 => 'Silné'] as $val => $label)
            <option value="{{ $val }}" {{ ($usersSettings['security_password_level'] ?? 3) == $val ? 'selected' : '' }}>
                {{ $label }}
            </option>
            @endforeach
        </select>
    </div>

    <div class="m-form-group">
        <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;font-size:13px">
            <input type="checkbox" name="former_member_auto_device_remove" value="1"
                style="margin-top:2px;flex-shrink:0"
                {{ ($usersSettings['former_member_auto_device_remove'] ?? '') == '1' ? 'checked' : '' }}>
            <span>
                <strong>Auto-mazání zařízení bývalých členů</strong>
                <div class="m-form-hint" style="margin-top:1px">Při označení jako bývalý člen automaticky smaže jeho zařízení a IP adresy.</div>
            </span>
        </label>
    </div>
</div>

<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Uložit nastavení uživatelů</button>
</div>
</form>
@endif

@if($activeTab === 'network')
<form method="POST" action="{{ route('settings.update-network') }}">
@csrf @method('PUT')

<div class="m-card" style="margin-bottom:16px;max-width:560px">
    <div class="m-card-title">Nastavení sítě</div>
    <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:12px">
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
            <input type="checkbox" name="redirection_enabled" value="1"
                {{ ($networkSettings['redirection_enabled'] ?? '') == '1' ? 'checked' : '' }}>
            Přesměrování povoleno
        </label>
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
            <input type="checkbox" name="networks_enabled" value="1"
                {{ ($networkSettings['networks_enabled'] ?? '') == '1' ? 'checked' : '' }}>
            Síťový modul povolen
        </label>
    </div>
    <div class="m-form-group">
        <label class="m-form-label">Rozsahy IP adres</label>
        <input class="m-form-input" type="text" name="address_ranges"
            value="{{ $networkSettings['address_ranges'] ?? '' }}" placeholder="10.133.0.0/16,185.138.44.0/22">
        <div class="m-form-hint">Oddělte čárkou.</div>
    </div>
    <div class="m-form-group">
        <label class="m-form-label">DNS servery</label>
        <input class="m-form-input" type="text" name="dns_servers"
            value="{{ $networkSettings['dns_servers'] ?? '' }}" placeholder="10.133.37.37">
        <div class="m-form-hint">Oddělte čárkou.</div>
    </div>
    <div class="m-form-group">
        <label class="m-form-label">DHCP lease time (s)</label>
        <input class="m-form-input" type="number" name="dhcp_lease_time"
            value="{{ $networkSettings['dhcp_lease_time'] ?? '10800' }}" min="60" style="max-width:120px">
        <div class="m-form-hint">Výchozí 10800 = 3 hodiny.</div>
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label">IPv6 prefix</label>
            <input class="m-form-input" type="text" name="ipv6_prefix"
                value="{{ $networkSettings['ipv6_prefix'] ?? '' }}" placeholder="2a07:9c0" style="font-family:monospace">
            <div class="m-form-hint">Společný prefix sítě (bez lomítka a délky masky).</div>
        </div>
        <div class="m-form-group">
            <label class="m-form-label">IPv6 maska (délka prefixu)</label>
            <input class="m-form-input" type="number" name="ipv6_mask"
                value="{{ $networkSettings['ipv6_mask'] ?? '' }}" min="1" max="128" placeholder="56" style="max-width:80px">
            <div class="m-form-hint">Délka prefixu v bitech (např. 56).</div>
        </div>
    </div>
    <div class="m-form-group">
        <label class="m-form-label">Notifikační email pro žádosti o připojení</label>
        <input class="m-form-input" type="email" name="connection_request_notify_email"
            value="{{ $networkSettings['connection_request_notify_email'] ?? '' }}" placeholder="admin@example.com">
        <div class="m-form-hint">Po odeslání žádosti bude na tento email odesláno upozornění. Prázdné = deaktivace.</div>
    </div>
</div>

<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Uložit nastavení sítě</button>
</div>
</form>
@endif

@if($activeTab === 'sms')
@php $smsDriverMeta = \App\Http\Controllers\SettingController::SMS_DRIVERS; @endphp
<form method="POST" action="{{ route('settings.update-sms') }}">
@csrf @method('PUT')

<div class="m-card" style="margin-bottom:16px;max-width:480px">
    <div class="m-card-title">Základní nastavení SMS</div>
    <div style="margin-bottom:12px">
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
            <input type="checkbox" name="sms_enabled" value="1"
                {{ ($smsSettings['sms_enabled'] ?? '0') == '1' ? 'checked' : '' }}>
            SMS povoleny
        </label>
        <div class="m-form-hint">Globální přepínač SMS notifikací.</div>
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label">Číslo odesílatele</label>
            <input class="m-form-input" type="text" name="sms_sender_number"
                value="{{ $smsSettings['sms_sender_number'] ?? '' }}" placeholder="420588207234" maxlength="20" style="font-family:monospace">
            <div class="m-form-hint">Číslo ve formátu bez + (420588207234).</div>
        </div>
        <div class="m-form-group">
            <label class="m-form-label">Výchozí driver</label>
            <select class="m-form-select" name="sms_driver">
                <option value="">— nevybráno —</option>
                @foreach($smsDriverMeta as $dId => $dCfg)
                <option value="{{ $dId }}" {{ ($smsSettings['sms_driver'] ?? '') == $dId ? 'selected' : '' }}>
                    {{ $dId }} – {{ $dCfg['name'] }}
                </option>
                @endforeach
            </select>
        </div>
    </div>
</div>

@foreach($smsDriverMeta as $dId => $dCfg)
@php $ds = $smsDriverSettings[$dId] ?? []; @endphp
<div class="m-card" style="margin-bottom:16px;max-width:480px">
    <div class="m-card-title">Driver {{ $dId }}: {{ $dCfg['name'] }}</div>
    <div class="m-form-group">
        <label class="m-form-label">Stav driveru</label>
        <select class="m-form-select" name="sms_driver_state{{ $dId }}" style="max-width:160px">
            <option value="1" {{ ($ds['state'] ?? '1') == '1' ? 'selected' : '' }}>Neaktivní</option>
            <option value="2" {{ ($ds['state'] ?? '1') == '2' ? 'selected' : '' }}>Aktivní</option>
        </select>
    </div>
    @if($dCfg['has_hostname'])
    <div class="m-form-group">
        <label class="m-form-label">Hostname</label>
        <input class="m-form-input" type="text" name="sms_hostname{{ $dId }}"
            value="{{ $ds['hostname'] ?? '' }}" placeholder="api.klikniavolej.cz:80">
    </div>
    @endif
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label">Uživatel</label>
            <input class="m-form-input" type="text" name="sms_user{{ $dId }}" value="{{ $ds['user'] ?? '' }}">
        </div>
        <div class="m-form-group">
            <label class="m-form-label">{{ $dId === 5 ? 'API klíč' : 'Heslo' }}</label>
            <input class="m-form-input" type="password" name="sms_password{{ $dId }}" value="{{ $ds['password'] ?? '' }}">
        </div>
    </div>
    @if($dCfg['has_test_mode'])
    <div class="m-form-group">
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
            <input type="checkbox" name="sms_test_mode{{ $dId }}" value="1"
                {{ ($ds['test_mode'] ?? '0') == '1' ? 'checked' : '' }}>
            Testovací mód (SMS se nezasílají)
        </label>
    </div>
    @endif
</div>
@endforeach

<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Uložit nastavení SMS</button>
</div>
</form>
@endif

</div>
@endsection
