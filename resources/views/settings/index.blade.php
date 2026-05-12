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
    @foreach(['banka' => 'Banka', 'email' => 'Email', 'finance' => 'Finance', 'system' => 'Systém', 'users' => 'Uživatelé', 'network' => 'Síť', 'sms' => 'SMS', 'gpon' => 'GPON', 'smlouvy' => 'Smlouvy', 'sledovanitv' => 'SledovaniTV'] as $tabKey => $tabLabel)
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
        <label style="display:flex;align-items:center;gap:6px;font-size:16px;cursor:pointer">
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
                    <button type="button" style="background:none;border:none;cursor:pointer;color:#c0392b;font-size:19px"
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

<div class="m-card" style="margin-bottom:16px;max-width:600px">
    <div class="m-card-title">Shrnutí registrace (PDF příloha)</div>
    <p class="m-form-hint" style="margin-bottom:12px">
        Po úspěšné registraci nového člena bude na jeho email odesláno PDF se shrnutím.
    </p>
    <div style="margin-bottom:12px">
        <label style="display:flex;align-items:center;gap:6px;font-size:16px;cursor:pointer">
            <input type="checkbox" name="registration_summary_enabled" value="1"
                {{ ($emailSettings['registration_summary_enabled'] ?? '') == '1' ? 'checked' : '' }}>
            Odesílat shrnutí registrace
        </label>
    </div>
    <div class="m-form-group">
        <label class="m-form-label">Cesta k PDF souboru</label>
        <input class="m-form-input" type="text" name="registration_summary_pdf"
            value="{{ $emailSettings['registration_summary_pdf'] ?? '' }}"
            placeholder="smlouva_shrnuti.pdf">
        <div class="m-form-hint">
            Relativní cesta vůči <code>storage/app/private/</code>, nebo absolutní cesta začínající <code>/</code>.
            Pokud soubor neexistuje, email se neodešle.
        </div>
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
        '<td><button type="button" style="background:none;border:none;cursor:pointer;color:#c0392b;font-size:19px" onclick="this.closest(\'tr\').remove()">✕</button></td>';
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
        <label style="display:flex;align-items:center;gap:6px;font-size:16px;cursor:pointer">
            <input type="checkbox" name="finance_enabled" value="1"
                {{ ($financeSettings['finance_enabled'] ?? '') == '1' ? 'checked' : '' }}>
            Finanční systém povolen
        </label>
        <label style="display:flex;align-items:center;gap:6px;font-size:16px;cursor:pointer">
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
            <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;font-size:16px">
                <input type="checkbox" name="self_registration" value="1"
                    style="margin-top:2px;flex-shrink:0"
                    {{ ($systemSettings['self_registration'] ?? '') == '1' ? 'checked' : '' }}>
                <span>
                    <strong>Samo-registrace</strong>
                    <div class="m-form-hint" style="margin-top:1px">Umožní nepřihlášeným uživatelům vyplnit registrační formulář.</div>
                </span>
            </label>
            <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;font-size:16px">
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
    <div class="m-card-title">Kontaktní údaje sdružení <span style="font-weight:400;text-transform:none;letter-spacing:0;color:#bbb;font-size:13px">(pro PDF výpovědi smlouvy)</span></div>

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
            <span style="font-size:16px;color:#888">znaků</span>
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
        <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;font-size:16px">
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
        <label style="display:flex;align-items:center;gap:6px;font-size:16px;cursor:pointer">
            <input type="checkbox" name="redirection_enabled" value="1"
                {{ ($networkSettings['redirection_enabled'] ?? '') == '1' ? 'checked' : '' }}>
            Přesměrování povoleno
        </label>
        <label style="display:flex;align-items:center;gap:6px;font-size:16px;cursor:pointer">
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

<div class="m-card" style="margin-top:16px;max-width:560px">
    <div class="m-card-title">DHCP API token</div>
    <div class="m-form-group">
        <label class="m-form-label">Token</label>
        <input class="m-form-input" type="text" readonly value="{{ $dhcpApiToken }}" style="font-family:monospace">
        <div class="m-form-hint">
            Použití: <code>/devices/{id}/export/mikrotik-ip-dhcp-server?token={{ $dhcpApiToken }}</code>
        </div>
    </div>
    <form method="POST" action="{{ route('settings.regenerate-dhcp-token') }}"
          onsubmit="return confirm('Regenerovat token? Stávající token přestane fungovat.')">
        @csrf
        <button type="submit" class="m-btn">Regenerovat token</button>
    </form>
</div>
@endif

@if($activeTab === 'sms')
@php $smsDriverMeta = \App\Http\Controllers\SettingController::SMS_DRIVERS; @endphp
<form method="POST" action="{{ route('settings.update-sms') }}">
@csrf @method('PUT')

<div class="m-card" style="margin-bottom:16px;max-width:480px">
    <div class="m-card-title">Základní nastavení SMS</div>
    <div style="margin-bottom:12px">
        <label style="display:flex;align-items:center;gap:6px;font-size:16px;cursor:pointer">
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
        <label style="display:flex;align-items:center;gap:6px;font-size:16px;cursor:pointer">
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

@if($activeTab === 'gpon')
<form method="POST" action="{{ route('settings.update-gpon') }}">
@csrf @method('PUT')

<div class="m-card" style="margin-bottom:16px;max-width:520px">
    <div class="m-card-title">GPON modul</div>
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:16px">
        <label style="display:flex;align-items:center;gap:6px;font-size:16px;cursor:pointer">
            <input type="checkbox" name="gpon_enabled" value="1"
                {{ ($gponSettings['gpon_enabled'] ?? '0') == '1' ? 'checked' : '' }}>
            Povolit GPON modul
        </label>
    </div>
</div>

<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Uložit nastavení GPON</button>
    @if(($gponSettings['gpon_enabled'] ?? '0') == '1')
    <a class="m-btn" href="{{ route('gpon.index') }}">Přejít na GPON</a>
    @endif
</div>
</form>

{{-- OLT zařízení --}}
<div class="m-card" style="margin-top:24px;margin-bottom:16px">
    <div class="m-card-title">OLT zařízení</div>

    @if($gponOlts->count())
    <table class="m-table" style="font-size:16px;margin-bottom:16px">
        <thead>
            <tr>
                <th>Název</th><th>IP</th><th>Port</th><th>Line profil</th>
                <th>Service profil</th><th>Traffic table</th><th>ONT</th><th>Akce</th>
            </tr>
        </thead>
        <tbody>
        @foreach($gponOlts as $golt)
        <tr>
            <td>{{ $golt->name }}</td>
            <td><code>{{ $golt->ip }}</code></td>
            <td>{{ $golt->gpon_port }}</td>
            <td>{{ $golt->line_prof }}</td>
            <td>{{ $golt->service_prof }}</td>
            <td>{{ $golt->traffic_table }}</td>
            <td>{{ $golt->onts_count }}</td>
            <td style="white-space:nowrap">
                <button class="m-btn" style="font-size:13px;padding:3px 8px"
                    data-olt-id="{{ $golt->id }}"
                    data-olt='{!! json_encode($golt->toArray(), JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG) !!}'
                    onclick="gponOltEdit(this)">Upravit</button>
                <form method="POST" action="{{ route('settings.gpon-olts.destroy', $golt->id) }}"
                    style="display:inline"
                    onsubmit="return confirm('Smazat OLT {{ addslashes($golt->name) }}?')">
                    @csrf @method('DELETE')
                    <button class="m-btn" style="font-size:13px;padding:3px 8px;background:#fee2e2;color:#b91c1c;border-color:#fca5a5"
                        type="submit">Smazat</button>
                </form>
            </td>
        </tr>
        @endforeach
        </tbody>
    </table>
    @else
    <p style="color:var(--fn-text-muted);font-size:16px;margin:0 0 16px">Žádné OLT zatím není nakonfigurováno.</p>
    @endif

    {{-- Formulář přidat/upravit OLT --}}
    <div id="gpon-olt-form-wrap">
        <div class="m-card-title" style="margin-bottom:12px" id="gpon-olt-form-title">Přidat OLT</div>
        <form id="gpon-olt-form" method="POST" action="{{ route('settings.gpon-olts.store') }}">
            @csrf
            <div class="m-form-row">
                <div class="m-form-group">
                    <label class="m-form-label">Název</label>
                    <input class="m-form-input" type="text" name="name" id="golt-name" placeholder="Produkce MA5800" required>
                </div>
                <div class="m-form-group">
                    <label class="m-form-label">IP adresa</label>
                    <input class="m-form-input" type="text" name="ip" id="golt-ip" placeholder="10.0.0.1" required>
                </div>
                <div class="m-form-group">
                    <label class="m-form-label">GPON port (frame/slot/X)</label>
                    <input class="m-form-input" type="text" name="gpon_port" id="golt-gpon-port" placeholder="0/1/0" required style="max-width:100px">
                </div>
            </div>
            <div class="m-form-row">
                <div class="m-form-group">
                    <label class="m-form-label">SNMP uživatel</label>
                    <input class="m-form-input" type="text" name="snmp_user" id="golt-snmp-user" placeholder="admin" required>
                </div>
                <div class="m-form-group">
                    <label class="m-form-label">Auth heslo</label>
                    <input class="m-form-input" type="password" name="snmp_auth_pass" id="golt-auth-pass" autocomplete="new-password" required>
                </div>
                <div class="m-form-group">
                    <label class="m-form-label">Priv heslo</label>
                    <input class="m-form-input" type="password" name="snmp_priv_pass" id="golt-priv-pass" autocomplete="new-password" required>
                </div>
                <div class="m-form-group">
                    <label class="m-form-label">Auth proto</label>
                    <select class="m-form-select" name="snmp_auth_proto" id="golt-auth-proto">
                        <option>SHA</option><option>MD5</option>
                    </select>
                </div>
                <div class="m-form-group">
                    <label class="m-form-label">Priv proto</label>
                    <select class="m-form-select" name="snmp_priv_proto" id="golt-priv-proto">
                        <option>AES</option><option>DES</option>
                    </select>
                </div>
            </div>
            <div class="m-form-row">
                <div class="m-form-group">
                    <label class="m-form-label">Line profil</label>
                    <input class="m-form-input" type="text" name="line_prof" id="golt-line-prof" value="line-profile_default_0" required>
                </div>
                <div class="m-form-group">
                    <label class="m-form-label">Service profil</label>
                    <input class="m-form-input" type="text" name="service_prof" id="golt-service-prof" value="sfu-aio-dmc" required>
                </div>
                <div class="m-form-group">
                    <label class="m-form-label">Traffic table</label>
                    <input class="m-form-input" type="text" name="traffic_table" id="golt-traffic-table" value="int" required style="max-width:100px">
                </div>
            </div>
            <div class="m-form-row">
                <div class="m-form-group">
                    <label class="m-form-label">Base VLAN</label>
                    <input class="m-form-input" type="number" name="base_vlan" id="golt-base-vlan" placeholder="200" style="max-width:90px">
                    <div class="m-form-hint">Použije se pokud není vlan_map</div>
                </div>
                <div class="m-form-group">
                    <label class="m-form-label">Počet portů</label>
                    <input class="m-form-input" type="number" name="port_count" placeholder="např. 8" min="1" max="128" style="max-width:90px">
                    <div class="m-form-hint">Počet GPON portů na OLT</div>
                </div>
                <div class="m-form-group" style="flex:2">
                    <label class="m-form-label">VLAN mapa (JSON, volitelné)</label>
                    <input class="m-form-input" type="text" name="vlan_map" id="golt-vlan-map"
                        placeholder='{"0":200,"1":200,...}' style="font-family:monospace;font-size:14px">
                </div>
                <div class="m-form-group">
                    <label class="m-form-label">Název obce (geokódování)</label>
                    <input class="m-form-input" type="text" name="geocode_city" id="golt-geocode-city"
                        placeholder="např. Určice" style="max-width:160px">
                    <div class="m-form-hint">Nominatim — prázdné = geokódování přeskočeno</div>
                </div>
            </div>
            <div class="m-actions" style="margin-top:8px">
                <button class="m-btn m-btn-primary" type="submit" id="golt-submit-btn">Přidat OLT</button>
                <button type="button" class="m-btn" id="golt-cancel-btn" style="display:none" onclick="gponOltReset()">Zrušit</button>
            </div>
        </form>
    </div>
</div>

<script>
function gponOltEdit(btn) {
    const olt = JSON.parse(btn.dataset.olt);
    console.log('edit olt:', olt);

    document.getElementById('gpon-olt-form-title').textContent = 'Upravit OLT: ' + olt.name;

    const form = document.getElementById('gpon-olt-form');
    form.action = '{{ url('settings/gpon-olts') }}/' + olt.id;

    let method = form.querySelector('input[name="_method"]');
    if (!method) {
        method = document.createElement('input');
        method.type = 'hidden';
        method.name = '_method';
        form.appendChild(method);
    }
    method.value = 'PUT';

    form.querySelector('[name="name"]').value          = olt.name          || '';
    form.querySelector('[name="ip"]').value            = olt.ip            || '';
    form.querySelector('[name="gpon_port"]').value     = olt.gpon_port     || '';
    form.querySelector('[name="snmp_user"]').value     = olt.snmp_user     || '';
    form.querySelector('[name="snmp_auth_pass"]').value = olt.snmp_auth_pass || '';
    form.querySelector('[name="snmp_priv_pass"]').value = olt.snmp_priv_pass || '';
    form.querySelector('[name="snmp_auth_proto"]').value = olt.snmp_auth_proto || 'SHA';
    form.querySelector('[name="snmp_priv_proto"]').value = olt.snmp_priv_proto || 'AES';
    form.querySelector('[name="line_prof"]').value     = olt.line_prof     || '';
    form.querySelector('[name="service_prof"]').value  = olt.service_prof  || '';
    form.querySelector('[name="traffic_table"]').value = olt.traffic_table || '';
    form.querySelector('[name="base_vlan"]').value     = olt.base_vlan     || '';
    form.querySelector('[name="port_count"]').value    = olt.port_count    || '';
    form.querySelector('[name="vlan_map"]').value      = olt.vlan_map ? JSON.stringify(olt.vlan_map) : '';
    form.querySelector('[name="geocode_city"]').value  = olt.geocode_city  || '';

    document.getElementById('golt-submit-btn').textContent   = 'Uložit změny';
    document.getElementById('golt-cancel-btn').style.display = 'inline-flex';
    form.scrollIntoView({behavior: 'smooth'});
}
function gponOltReset() {
    const form = document.getElementById('gpon-olt-form');
    document.getElementById('gpon-olt-form-title').textContent = 'Přidat OLT';
    form.action = '{{ route("settings.gpon-olts.store") }}';
    const method = form.querySelector('input[name="_method"]');
    if (method) method.remove();
    form.reset();
    document.getElementById('golt-submit-btn').textContent   = 'Přidat OLT';
    document.getElementById('golt-cancel-btn').style.display = 'none';
}
</script>

@endif

@if($activeTab === 'smlouvy')
<form method="POST" action="{{ route('settings.update-smlouvy') }}" enctype="multipart/form-data">
@csrf @method('PUT')

<div class="m-card" style="margin-bottom:16px;max-width:640px">
    <div class="m-card-title">OTP (jednorázové kódy)</div>
    <div class="m-form-group">
        <label class="m-form-label">OTP pepper (tajný klíč)</label>
        <input class="m-form-input" type="password" name="otp_pepper"
            value="" placeholder="{{ $smlouvySettings['otp_pepper'] ? '••••••••' : '' }}" autocomplete="new-password">
        <div class="m-form-hint">Sůl pro hashování OTP. Ponechte prázdné pro zachování stávající hodnoty.</div>
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label">Platnost OTP (minuty)</label>
            <input class="m-form-input" type="number" name="otp_ttl_min" min="1" max="60"
                value="{{ $smlouvySettings['otp_ttl_min'] }}" style="max-width:100px">
        </div>
        <div class="m-form-group">
            <label class="m-form-label">Max. počet pokusů</label>
            <input class="m-form-input" type="number" name="otp_max_attempts" min="1" max="20"
                value="{{ $smlouvySettings['otp_max_attempts'] }}" style="max-width:100px">
        </div>
        <div class="m-form-group">
            <label class="m-form-label">Resend okno (sekundy)</label>
            <input class="m-form-input" type="number" name="otp_resend_window_sec" min="0" max="3600"
                value="{{ $smlouvySettings['otp_resend_window_sec'] }}" style="max-width:120px">
        </div>
    </div>
    <div class="m-form-row">
        <div class="m-form-group" style="flex:0 0 auto">
            <label style="display:flex;align-items:center;gap:6px;font-size:16px;cursor:pointer;margin-top:24px">
                <input type="checkbox" name="otp_test_mode" value="1"
                    {{ $smlouvySettings['otp_test_mode'] == '1' ? 'checked' : '' }}>
                Testovací režim OTP
            </label>
        </div>
        <div class="m-form-group">
            <label class="m-form-label">Testovací OTP kód</label>
            <input class="m-form-input" type="text" name="otp_test_code"
                value="{{ $smlouvySettings['otp_test_code'] }}" style="max-width:160px">
            <div class="m-form-hint">Pevný kód, který v testovacím režimu projde místo skutečného OTP.</div>
        </div>
    </div>
</div>

<div class="m-card" style="margin-bottom:16px;max-width:640px">
    <div class="m-card-title">Elektronický podpis PDF</div>
    <div class="m-form-group">
        <label class="m-form-label">Cesta k certifikátu (.pfx)</label>
        <input class="m-form-input" type="text" name="pdf_sign_cert"
            value="{{ $smlouvySettings['pdf_sign_cert'] }}"
            placeholder="storage/app/private/certs/pvfree.pfx">
        <div class="m-form-hint">Relativní k root adresáři aplikace, nebo absolutní cesta.</div>
    </div>
    <div class="m-form-group">
        <label class="m-form-label">Nebo nahraj nový .pfx soubor</label>
        <input class="m-form-input" type="file" name="pdf_sign_cert_file" accept=".pfx,.p12">
        <div class="m-form-hint">Uloží se do <code>storage/app/private/certs/</code> a cesta se automaticky vyplní.</div>
        @error('pdf_sign_cert_file') <div class="field-error" style="color:#b91c1c;font-size:14px;margin-top:4px">{{ $message }}</div> @enderror
    </div>
    <div class="m-form-group">
        <label class="m-form-label">Heslo k certifikátu</label>
        <input class="m-form-input" type="password" name="pdf_sign_pass"
            value="" placeholder="{{ $smlouvySettings['pdf_sign_pass'] ? '••••••••' : '' }}" autocomplete="new-password">
        <div class="m-form-hint">Ponechte prázdné pro zachování stávající hodnoty.</div>
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label">Jméno podepisujícího</label>
            <input class="m-form-input" type="text" name="pdf_sign_name"
                value="{{ $smlouvySettings['pdf_sign_name'] }}">
        </div>
        <div class="m-form-group">
            <label class="m-form-label">Místo podpisu</label>
            <input class="m-form-input" type="text" name="pdf_sign_location"
                value="{{ $smlouvySettings['pdf_sign_location'] }}">
        </div>
    </div>
    <div class="m-form-group">
        <label class="m-form-label">Důvod podpisu</label>
        <input class="m-form-input" type="text" name="pdf_sign_reason"
            value="{{ $smlouvySettings['pdf_sign_reason'] }}">
    </div>
</div>

<div class="m-card" style="margin-bottom:16px;max-width:640px">
    <div class="m-card-title">Email po podpisu smlouvy</div>
    <p class="m-form-hint" style="margin-bottom:12px">
        Po úspěšném podpisu se zákazníkovi automaticky odešle email s podepsanou smlouvou, ceníkem a VOP.
    </p>
    <div style="margin-bottom:12px">
        <label style="display:flex;align-items:center;gap:6px;font-size:16px;cursor:pointer">
            <input type="checkbox" name="contract_email_attachments_enabled" value="1"
                {{ ($smlouvySettings['contract_email_attachments_enabled'] ?? '') == '1' ? 'checked' : '' }}>
            Odesílat email s přílohami po podpisu
        </label>
    </div>
    <div class="m-form-group">
        <label class="m-form-label">Cesta k ceníku (PDF)</label>
        <input class="m-form-input" type="text" name="contract_email_pricelist_pdf"
            value="{{ $smlouvySettings['contract_email_pricelist_pdf'] }}"
            placeholder="storage/app/private/contract-attachments/cenik.pdf">
    </div>
    <div class="m-form-group">
        <label class="m-form-label">Nebo nahraj nový ceník (PDF)</label>
        <input class="m-form-input" type="file" name="contract_email_pricelist_pdf_file" accept=".pdf,application/pdf">
        <div class="m-form-hint">Uloží se do <code>storage/app/private/contract-attachments/</code> a cesta se vyplní.</div>
        @error('contract_email_pricelist_pdf_file') <div class="field-error" style="color:#b91c1c;font-size:14px;margin-top:4px">{{ $message }}</div> @enderror
    </div>
    <div class="m-form-group">
        <label class="m-form-label">Cesta k VOP (PDF)</label>
        <input class="m-form-input" type="text" name="contract_email_vop_pdf"
            value="{{ $smlouvySettings['contract_email_vop_pdf'] }}"
            placeholder="storage/app/private/contract-attachments/vop.pdf">
        <div class="m-form-hint">
            Relativní vůči root adresáři aplikace, nebo absolutní cesta. Pokud soubor neexistuje, jen se přeskočí (smlouva se odešle bez něj).
        </div>
    </div>
    <div class="m-form-group">
        <label class="m-form-label">Nebo nahraj nové VOP (PDF)</label>
        <input class="m-form-input" type="file" name="contract_email_vop_pdf_file" accept=".pdf,application/pdf">
        <div class="m-form-hint">Uloží se do <code>storage/app/private/contract-attachments/</code> a cesta se vyplní.</div>
        @error('contract_email_vop_pdf_file') <div class="field-error" style="color:#b91c1c;font-size:14px;margin-top:4px">{{ $message }}</div> @enderror
    </div>
</div>

<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Uložit nastavení smluv</button>
</div>
</form>
@endif

@if($activeTab === 'sledovanitv')
<form method="POST" action="{{ route('settings.update-sledovanitv') }}">
@csrf @method('PUT')

<div class="m-card" style="margin-bottom:16px;max-width:560px">
    <div class="m-card-title">SledovaniTV — read-only modul</div>
    <p class="m-form-hint" style="margin-bottom:12px">
        Pravidelně stahuje seznam zákazníků z partner API a v detailu člena ukazuje stav TV předplatného.
        Mapping <code>users[].partnerid → members.id</code>, "aktivní" = <code>partnerActivation ≥ dnes</code>.
    </p>

    <div class="m-form-group">
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
            <input type="checkbox" name="sledovanitv_enabled" value="1"
                {{ ($sledovanitvSettings['sledovanitv_enabled'] ?? '') == '1' ? 'checked' : '' }}>
            Modul povolen
        </label>
        <div class="m-form-hint">Když je vypnutý, badge se v UI nezobrazuje a denní cron je no-op.</div>
    </div>

    <div class="m-form-group">
        <label class="m-form-label">Partner</label>
        <input class="m-form-input" type="text" name="sledovanitv_partner"
            value="{{ $sledovanitvSettings['sledovanitv_partner'] ?? '' }}" placeholder="např. pvfree">
    </div>

    <div class="m-form-group">
        <label class="m-form-label">Heslo</label>
        <input class="m-form-input" type="password" name="sledovanitv_password" autocomplete="new-password"
            placeholder="{{ ($sledovanitvSettings['sledovanitv_password'] ?? '') !== '' ? '••••• (uložené)' : 'API password' }}">
        <div class="m-form-hint">Nech prázdné, pokud nechceš měnit. Uložené heslo se nezobrazuje.</div>
    </div>
</div>

<div class="m-card" style="margin-bottom:16px;max-width:560px">
    <div class="m-card-title">Stav synchronizace</div>
    <div class="m-field">
        <span class="m-field-label">Poslední sync</span>
        <span class="m-field-value">{{ $sledovanitvSettings['last_sync'] ?: '—' }}</span>
    </div>
    <div class="m-field">
        <span class="m-field-label">Výsledek</span>
        <span class="m-field-value">{{ $sledovanitvSettings['last_sync_status'] ?: '—' }}</span>
    </div>
    <div class="m-form-hint" style="margin-top:8px">
        Cron běží denně v 03:30. Tlačítkem dole můžeš spustit ručně (vyžaduje uložené credentials).
    </div>
</div>

<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Uložit nastavení</button>
</div>
</form>

<form method="POST" action="{{ route('settings.sledovanitv-sync') }}" style="margin-top:8px">
    @csrf
    <button class="m-btn" type="submit"
            onclick="return confirm('Spustit sync teď? Stáhne se aktuální seznam ze SledovaniTV API a aktualizují se všichni členové.')"
            {{ ($sledovanitvSettings['sledovanitv_enabled'] ?? '') == '1' ? '' : 'disabled' }}>
        &#8635; Spustit sync teď
    </button>
</form>
@endif

</div>
@endsection
