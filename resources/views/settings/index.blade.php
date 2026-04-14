@extends('layouts.app')
@section('title', 'Nastavení')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
    <div id="breadcrumbs">Nastavení</div>
@endsection
@section('content')
    <h2>Nastavení</h2>

    {{-- Tabs --}}
    <div style="margin-bottom:1em; border-bottom:2px solid #c00;">
        <a href="{{ route('settings.index', ['tab' => 'banka']) }}"
           style="display:inline-block; padding:6px 16px; margin-right:4px; text-decoration:none;
                  {{ $activeTab === 'banka' ? 'background:#c00; color:#fff; font-weight:bold;' : 'background:#eee; color:#333;' }}">
            Banka
        </a>
        <a href="{{ route('settings.index', ['tab' => 'email']) }}"
           style="display:inline-block; padding:6px 16px; margin-right:4px; text-decoration:none;
                  {{ $activeTab === 'email' ? 'background:#c00; color:#fff; font-weight:bold;' : 'background:#eee; color:#333;' }}">
            Email
        </a>
        <a href="{{ route('settings.index', ['tab' => 'finance']) }}"
           style="display:inline-block; padding:6px 16px; margin-right:4px; text-decoration:none;
                  {{ $activeTab === 'finance' ? 'background:#c00; color:#fff; font-weight:bold;' : 'background:#eee; color:#333;' }}">
            Finance
        </a>
        @foreach(['system' => 'Systém', 'users' => 'Uživatelé', 'network' => 'Síť', 'sms' => 'SMS'] as $tabKey => $tabLabel)
        <a href="{{ route('settings.index', ['tab' => $tabKey]) }}"
           style="display:inline-block; padding:6px 16px; margin-right:4px; text-decoration:none;
                  {{ $activeTab === $tabKey ? 'background:#c00; color:#fff; font-weight:bold;' : 'background:#eee; color:#333;' }}">
            {{ $tabLabel }}
        </a>
        @endforeach
    </div>

    {{-- ═══════════════════════════════════════════════════ --}}
    {{-- TAB: BANKA                                         --}}
    {{-- ═══════════════════════════════════════════════════ --}}
    @if($activeTab === 'banka')
    <form method="POST" action="{{ route('settings.update') }}">
        @csrf @method('PUT')

        <h3>Přiřazení bankovních účtů k typům členů (import výpisů)</h3>
        <p>
            Platby přicházející na nesprávný bankovní účet pro daný typ člena
            zůstanou nespárované. Toto odpovídá logice
            <code>pvfree_filter_member_by_bank_account</code> z původního systému.
        </p>

        <table class="extended" cellspacing="0">
            <thead>
                <tr>
                    <th>Typ člena</th>
                    <th>Bankovní účet pro příjem plateb</th>
                    <th>Vystavovat faktury?</th>
                </tr>
            </thead>
            <tbody>
                @foreach($routing as $type => $rule)
                    <tr>
                        <th>{{ $rule['label'] }}</th>
                        <td>
                            <select name="routing_{{ $type }}">
                                <option value="0">(bez omezení)</option>
                                @foreach($bankAccounts as $ba)
                                    <option value="{{ $ba->id }}"
                                        {{ $rule['bank_account_id'] == $ba->id ? 'selected' : '' }}>
                                        {{ $ba->name }} ({{ $ba->full_account_number }})
                                    </option>
                                @endforeach
                            </select>
                        </td>
                        <td style="text-align:center;">
                            <input type="checkbox"
                                   name="payment_purpose_{{ $type }}"
                                   value="1"
                                   {{ $rule['payment_purpose'] == 1 ? 'checked' : '' }}>
                        </td>
                    </tr>
                @endforeach
                <tr>
                    <th>Výchozí účet pro import<br><small>(použit když typ nemá pravidlo)</small></th>
                    <td>
                        <select name="default_bank_account_id">
                            <option value="0">(neurčen)</option>
                            @foreach($bankAccounts as $ba)
                                <option value="{{ $ba->id }}"
                                    {{ $defaultBaId == $ba->id ? 'selected' : '' }}>
                                    {{ $ba->name }} ({{ $ba->full_account_number }})
                                </option>
                            @endforeach
                        </select>
                    </td>
                    <td></td>
                </tr>
            </tbody>
        </table>

        <h3 style="margin-top:1.5em;">Pohoda export</h3>
        <table class="extended" cellspacing="0">
            <tr>
                <th>Email účetní (Pohoda export)</th>
                <td>
                    <input type="text" name="pohoda_accountant_email"
                           value="{{ $pohodaEmail }}"
                           style="width:280px"
                           placeholder="accountant@example.com">
                    <small style="color:#888;">Měsíční XML export faktur bude zasílán na tuto adresu.</small>
                </td>
            </tr>
        </table>

        <div style="margin-top:1em;">
            <button type="submit">Uložit nastavení banky</button>
        </div>
    </form>
    @endif

    {{-- ═══════════════════════════════════════════════════ --}}
    {{-- TAB: EMAIL                                         --}}
    {{-- ═══════════════════════════════════════════════════ --}}
    @if($activeTab === 'email')
    <form method="POST" action="{{ route('settings.update-email') }}">
        @csrf @method('PUT')

        <h3>Nastavení odchozí pošty</h3>
        <table class="extended" cellspacing="0">
            <tr>
                <th>Povoleno</th>
                <td>
                    <input type="checkbox" name="email_enabled" value="1"
                        {{ ($emailSettings['email_enabled'] ?? '') == '1' ? 'checked' : '' }}>
                </td>
            </tr>
            <tr>
                <th>Driver</th>
                <td>
                    <select name="email_driver">
                        @foreach(['smtp', 'sendmail'] as $d)
                            <option value="{{ $d }}" {{ ($emailSettings['email_driver'] ?? '') === $d ? 'selected' : '' }}>
                                {{ $d }}
                            </option>
                        @endforeach
                    </select>
                </td>
            </tr>
            <tr>
                <th>SMTP server</th>
                <td><input type="text" name="email_hostname" value="{{ $emailSettings['email_hostname'] ?? '' }}" style="width:250px"></td>
            </tr>
            <tr>
                <th>Port</th>
                <td><input type="text" name="email_port" value="{{ $emailSettings['email_port'] ?? '' }}" style="width:80px"></td>
            </tr>
            <tr>
                <th>Šifrování</th>
                <td>
                    <select name="email_encryption">
                        @foreach(['' => '(žádné)', 'tls' => 'TLS', 'ssl' => 'SSL'] as $val => $label)
                            <option value="{{ $val }}" {{ ($emailSettings['email_encryption'] ?? '') === $val ? 'selected' : '' }}>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                </td>
            </tr>
            <tr>
                <th>Uživatelské jméno</th>
                <td><input type="text" name="email_username" value="{{ $emailSettings['email_username'] ?? '' }}" style="width:250px"></td>
            </tr>
            <tr>
                <th>Heslo</th>
                <td><input type="password" name="email_password" value="{{ $emailSettings['email_password'] ?? '' }}" style="width:250px"></td>
            </tr>
            <tr>
                <th>Odesílatel (from)</th>
                <td><input type="text" name="email_default_email" value="{{ $emailSettings['email_default_email'] ?? '' }}" style="width:250px"></td>
            </tr>
        </table>

        <h3 style="margin-top:1.5em;">Kopie emailů (BCC pravidla)</h3>
        <p style="font-size:0.9em; color:#555;">
            Pokud předmět odeslaného emailu <strong>obsahuje</strong> zadaný text, odešle se kopie na zadanou adresu.
            Zprávu vyberte pro automatické vyplnění předmětu.
        </p>
        <table class="extended" cellspacing="0">
            <thead>
                <tr>
                    <th>Zpráva (volitelné)</th>
                    <th>Předmět obsahuje</th>
                    <th>BCC adresa</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="bcc-rules">
                @foreach($bccRules as $i => $rule)
                    <tr>
                        <td>
                            <select name="bcc_message_id[]" style="width:200px" onchange="prefillSubject(this)">
                                <option value="">— vyberte zprávu —</option>
                                @foreach($messages as $msg)
                                    <option value="{{ $msg->id }}"
                                        data-name="{{ $msg->name }}"
                                        {{ ($rule['message_id'] ?? '') == $msg->id ? 'selected' : '' }}>
                                        {{ $msg->name }}
                                    </option>
                                @endforeach
                            </select>
                        </td>
                        <td>
                            <input type="text" name="bcc_subject_prefix[]"
                                   value="{{ $rule['subject_prefix'] ?? '' }}"
                                   style="width:200px"
                                   placeholder="např. Faktura ">
                        </td>
                        <td>
                            <input type="text" name="bcc_address[]"
                                   value="{{ $rule['address'] ?? '' }}"
                                   style="width:180px"
                                   placeholder="kopie@example.com">
                        </td>
                        <td><button type="button" onclick="this.closest('tr').remove()">✕</button></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div style="margin-top:0.5em;">
            <button type="button" onclick="addBccRow()">+ Přidat pravidlo</button>
        </div>

        <div style="margin-top:1.5em;">
            <button type="submit">Uložit nastavení emailu</button>
        </div>
    </form>

    <script>
    function prefillSubject(select) {
        const name = select.options[select.selectedIndex].dataset.name || '';
        const row = select.closest('tr');
        const prefixInput = row.querySelector('input[name="bcc_subject_prefix[]"]');
        if (prefixInput && prefixInput.value === '') {
            prefixInput.value = name;
        }
    }

    const messageOptions = `{!! collect($messages)->map(fn($m) => '<option value="' . $m->id . '" data-name="' . e($m->name) . '">' . e($m->name) . '</option>')->implode('') !!}`;

    function addBccRow() {
        const tbody = document.getElementById('bcc-rules');
        const tr = document.createElement('tr');
        tr.innerHTML =
            '<td><select name="bcc_message_id[]" style="width:200px" onchange="prefillSubject(this)">' +
                '<option value="">— vyberte zprávu —</option>' + messageOptions +
            '</select></td>' +
            '<td><input type="text" name="bcc_subject_prefix[]" style="width:200px" placeholder="např. Faktura "></td>' +
            '<td><input type="text" name="bcc_address[]" style="width:180px" placeholder="kopie@example.com"></td>' +
            '<td><button type="button" onclick="this.closest(\'tr\').remove()">✕</button></td>';
        tbody.appendChild(tr);
    }
    </script>
    @endif

    {{-- ═══════════════════════════════════════════════════ --}}
    {{-- TAB: FINANCE                                        --}}
    {{-- ═══════════════════════════════════════════════════ --}}
    @if($activeTab === 'finance')
    <form method="POST" action="{{ route('settings.update-finance') }}">
        @csrf @method('PUT')

        <h3>Nastavení financí</h3>
        <table class="extended" cellspacing="0">
            <tr>
                <th>Finanční systém povolen</th>
                <td>
                    <input type="checkbox" name="finance_enabled" value="1"
                        {{ ($financeSettings['finance_enabled'] ?? '') == '1' ? 'checked' : '' }}>
                </td>
            </tr>
            <tr>
                <th>Automatické strhávání poplatků</th>
                <td>
                    <input type="checkbox" name="deduct_fees_automatically_enabled" value="1"
                        {{ ($financeSettings['deduct_fees_automatically_enabled'] ?? '') == '1' ? 'checked' : '' }}>
                </td>
            </tr>
            <tr>
                <th>Den strhávání poplatků</th>
                <td>
                    <input type="number" name="deduct_day"
                           value="{{ $financeSettings['deduct_day'] ?? 1 }}"
                           min="1" max="31" style="width:80px">
                    <small style="color:#888;">Den v měsíci (1–31), kdy se automaticky strhávají poplatky.</small>
                </td>
            </tr>
            <tr>
                <th>Výchozí tarif — Zákazník (typ 2)</th>
                <td>
                    <select name="default_fee_member_type_2">
                        <option value="">— nevybráno —</option>
                        @foreach($feesForSelect as $fee)
                            <option value="{{ $fee->id }}"
                                {{ ($financeSettings['default_fee_member_type_2'] ?? '') == $fee->id ? 'selected' : '' }}>
                                {{ $fee->name }} — {{ number_format($fee->fee, 2, ',', ' ') }} Kč
                                (od {{ $fee->from }}{{ $fee->to ? ' do ' . $fee->to : ' ∞' }})
                            </option>
                        @endforeach
                    </select>
                    <small style="color:#888;">Použije se pokud člen nemá individuální tarif.</small>
                </td>
            </tr>
            <tr>
                <th>Výchozí tarif — Člen (typ 90)</th>
                <td>
                    <select name="default_fee_member_type_90">
                        <option value="">— nevybráno —</option>
                        @foreach($feesForSelect as $fee)
                            <option value="{{ $fee->id }}"
                                {{ ($financeSettings['default_fee_member_type_90'] ?? '') == $fee->id ? 'selected' : '' }}>
                                {{ $fee->name }} — {{ number_format($fee->fee, 2, ',', ' ') }} Kč
                                (od {{ $fee->from }}{{ $fee->to ? ' do ' . $fee->to : ' ∞' }})
                            </option>
                        @endforeach
                    </select>
                    <small style="color:#888;">Použije se pokud člen nemá individuální tarif.</small>
                </td>
            </tr>
        </table>

        <div style="margin-top:1em;">
            <button type="submit">Uložit nastavení financí</button>
        </div>
    </form>
    @endif

    {{-- ═══════════════════════════════════════════════════ --}}
    {{-- TAB: SYSTÉM                                        --}}
    {{-- ═══════════════════════════════════════════════════ --}}
    @if($activeTab === 'system')
    <form method="POST" action="{{ route('settings.update-system') }}">
        @csrf @method('PUT')
        <h3>Nastavení systému</h3>
        <table class="extended" cellspacing="0">
            <tr>
                <th>Titulek stránky</th>
                <td><input type="text" name="title" value="{{ $systemSettings['title'] ?? '' }}" style="width:250px"></td>
            </tr>
            <tr>
                <th>IČO organizace</th>
                <td><input type="text" name="ico" value="{{ $systemSettings['ico'] ?? '' }}" style="width:150px"></td>
            </tr>
            <tr>
                <th>DIČ organizace</th>
                <td><input type="text" name="dic" value="{{ $systemSettings['dic'] ?? '' }}" style="width:150px"></td>
            </tr>
            <tr>
                <th>Samo-registrace</th>
                <td>
                    <input type="checkbox" name="self_registration" value="1"
                        {{ ($systemSettings['self_registration'] ?? '') == '1' ? 'checked' : '' }}>
                    <small style="color:#888;">Umožní nepřihlášeným uživatelům vyplnit registrační formulář.</small>
                </td>
            </tr>
            <tr>
                <th>Zapomenuté heslo</th>
                <td>
                    <input type="checkbox" name="forgotten_password" value="1"
                        {{ ($systemSettings['forgotten_password'] ?? '') == '1' ? 'checked' : '' }}>
                    <small style="color:#888;">Zobrazí odkaz pro reset hesla na přihlašovací stránce.</small>
                </td>
            </tr>
            <tr>
                <th>Vypršení session (s)</th>
                <td>
                    <input type="number" name="session_expiration" value="{{ $systemSettings['session_expiration'] ?? 7200 }}" style="width:100px" min="300">
                    <small style="color:#888;">Doba nečinnosti v sekundách (výchozí: 7200 = 2 hodiny).</small>
                </td>
            </tr>
        </table>
        <h3>Kontaktní údaje sdružení (pro PDF výpovědi smlouvy)</h3>
        <table class="extended" cellspacing="0">
            <tr>
                <th>Web (www)</th>
                <td>
                    <input type="text" name="association_www"
                           value="{{ $systemSettings['association_www'] ?? '' }}"
                           style="width:250px" placeholder="např. www.pvfree.net">
                </td>
            </tr>
            <tr>
                <th>E-mail</th>
                <td>
                    <input type="text" name="association_email"
                           value="{{ $systemSettings['association_email'] ?? '' }}"
                           style="width:250px" placeholder="např. spravci@pvfree.net">
                </td>
            </tr>
            <tr>
                <th>Telefon</th>
                <td>
                    <input type="text" name="association_phone"
                           value="{{ $systemSettings['association_phone'] ?? '' }}"
                           style="width:200px" placeholder="např. 588 207 234">
                </td>
            </tr>
            <tr>
                <th>Spolkový rejstřík — soud</th>
                <td>
                    <input type="text" name="association_court"
                           value="{{ $systemSettings['association_court'] ?? '' }}"
                           style="width:300px" placeholder="např. Krajský soud v Brně">
                </td>
            </tr>
            <tr>
                <th>Spolkový rejstřík — spisová značka</th>
                <td>
                    <input type="text" name="association_court_ref"
                           value="{{ $systemSettings['association_court_ref'] ?? '' }}"
                           style="width:150px" placeholder="např. L 10341">
                </td>
            </tr>
        </table>
        <div style="margin-top:1em;"><button type="submit">Uložit nastavení systému</button></div>
    </form>
    @endif

    {{-- ═══════════════════════════════════════════════════ --}}
    {{-- TAB: UŽIVATELÉ                                     --}}
    {{-- ═══════════════════════════════════════════════════ --}}
    @if($activeTab === 'users')
    <form method="POST" action="{{ route('settings.update-users') }}">
        @csrf @method('PUT')
        <h3>Nastavení uživatelů</h3>
        <table class="extended" cellspacing="0">
            <tr>
                <th>Minimální délka hesla</th>
                <td>
                    <input type="number" name="security_password_length"
                           value="{{ $usersSettings['security_password_length'] ?? 8 }}"
                           style="width:80px" min="4" max="32">
                    <small style="color:#888;">Minimální počet znaků hesla (výchozí: 8).</small>
                </td>
            </tr>
            <tr>
                <th>Minimální úroveň hesla</th>
                <td>
                    <select name="security_password_level">
                        @foreach([1 => 'Velmi slabé', 2 => 'Slabé', 3 => 'Dobré', 4 => 'Silné'] as $val => $label)
                            <option value="{{ $val }}"
                                {{ ($usersSettings['security_password_level'] ?? 3) == $val ? 'selected' : '' }}>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                </td>
            </tr>
            <tr>
                <th>Auto-mazání zařízení bývalých členů</th>
                <td>
                    <input type="checkbox" name="former_member_auto_device_remove" value="1"
                        {{ ($usersSettings['former_member_auto_device_remove'] ?? '') == '1' ? 'checked' : '' }}>
                    <small style="color:#888;">Při označení jako bývalý člen automaticky smaže jeho zařízení a IP adresy.</small>
                </td>
            </tr>
        </table>
        <div style="margin-top:1em;"><button type="submit">Uložit nastavení uživatelů</button></div>
    </form>
    @endif

    {{-- ═══════════════════════════════════════════════════ --}}
    {{-- TAB: SÍŤ                                           --}}
    {{-- ═══════════════════════════════════════════════════ --}}
    @if($activeTab === 'network')
    <form method="POST" action="{{ route('settings.update-network') }}">
        @csrf @method('PUT')
        <h3>Nastavení sítě</h3>
        <table class="extended" cellspacing="0">
            <tr>
                <th>Přesměrování povoleno</th>
                <td>
                    <input type="checkbox" name="redirection_enabled" value="1"
                        {{ ($networkSettings['redirection_enabled'] ?? '') == '1' ? 'checked' : '' }}>
                    <small style="color:#888;">Globální přepínač HTTP přesměrování dlužníků.</small>
                </td>
            </tr>
            <tr>
                <th>Síťový modul povolen</th>
                <td>
                    <input type="checkbox" name="networks_enabled" value="1"
                        {{ ($networkSettings['networks_enabled'] ?? '') == '1' ? 'checked' : '' }}>
                </td>
            </tr>
            <tr>
                <th>Rozsahy IP adres</th>
                <td>
                    <input type="text" name="address_ranges"
                           value="{{ $networkSettings['address_ranges'] ?? '' }}"
                           style="width:350px" placeholder="10.133.0.0/16,185.138.44.0/22">
                    <small style="color:#888;">Oddělte čárkou.</small>
                </td>
            </tr>
            <tr>
                <th>DNS servery</th>
                <td>
                    <input type="text" name="dns_servers"
                           value="{{ $networkSettings['dns_servers'] ?? '' }}"
                           style="width:350px" placeholder="10.133.37.37">
                </td>
            </tr>
            <tr>
                <th>IPv6 prefix</th>
                <td>
                    <input type="text" name="ipv6_prefix"
                           value="{{ $networkSettings['ipv6_prefix'] ?? '' }}"
                           style="width:200px" placeholder="2a07:9c0">
                    <small style="color:#888;">Společný prefix sítě (bez lomítka a délky masky).</small>
                </td>
            </tr>
            <tr>
                <th>IPv6 maska (délka prefixu)</th>
                <td>
                    <input type="number" name="ipv6_mask"
                           value="{{ $networkSettings['ipv6_mask'] ?? '' }}"
                           style="width:60px" min="1" max="128" placeholder="56">
                    <small style="color:#888;">Délka prefixu v bitech (např. 56).</small>
                </td>
            </tr>
        </table>
        <div style="margin-top:1em;"><button type="submit">Uložit nastavení sítě</button></div>
    </form>
    @endif

    {{-- ═══════════════════════════════════════════════════ --}}
    {{-- TAB: SMS                                           --}}
    {{-- ═══════════════════════════════════════════════════ --}}
    @if($activeTab === 'sms')
    @php
        $smsDriverMeta = \App\Http\Controllers\SettingController::SMS_DRIVERS;
    @endphp
    <form method="POST" action="{{ route('settings.update-sms') }}">
        @csrf @method('PUT')

        <h3>Základní nastavení SMS</h3>
        <table class="extended" cellspacing="0">
            <tr>
                <th>SMS povoleny</th>
                <td>
                    <input type="checkbox" name="sms_enabled" value="1"
                        {{ ($smsSettings['sms_enabled'] ?? '0') == '1' ? 'checked' : '' }}>
                    <small style="color:#888;">Globální přepínač SMS notifikací.</small>
                </td>
            </tr>
            <tr>
                <th>Číslo odesílatele</th>
                <td>
                    <input type="text" name="sms_sender_number"
                           value="{{ $smsSettings['sms_sender_number'] ?? '' }}"
                           style="width:180px" placeholder="420588207234" maxlength="20">
                    <small style="color:#888;">Číslo ve formátu bez + (např. 420588207234).</small>
                </td>
            </tr>
            <tr>
                <th>Výchozí driver</th>
                <td>
                    <select name="sms_driver">
                        <option value="">— nevybráno —</option>
                        @foreach($smsDriverMeta as $dId => $dCfg)
                            <option value="{{ $dId }}"
                                {{ ($smsSettings['sms_driver'] ?? '') == $dId ? 'selected' : '' }}>
                                {{ $dId }} – {{ $dCfg['name'] }}
                            </option>
                        @endforeach
                    </select>
                    <small style="color:#888;">Driver použitý pro zařazování nových SMS do fronty.</small>
                </td>
            </tr>
        </table>

        @foreach($smsDriverMeta as $dId => $dCfg)
        @php $ds = $smsDriverSettings[$dId] ?? []; @endphp
        <h3 style="margin-top:1.5em;">Driver {{ $dId }}: {{ $dCfg['name'] }}</h3>
        <table class="extended" cellspacing="0">
            <tr>
                <th>Stav driveru</th>
                <td>
                    <select name="sms_driver_state{{ $dId }}">
                        <option value="1" {{ ($ds['state'] ?? '1') == '1' ? 'selected' : '' }}>Neaktivní</option>
                        <option value="2" {{ ($ds['state'] ?? '1') == '2' ? 'selected' : '' }}>Aktivní</option>
                    </select>
                </td>
            </tr>
            @if($dCfg['has_hostname'])
            <tr>
                <th>Hostname</th>
                <td>
                    <input type="text" name="sms_hostname{{ $dId }}"
                           value="{{ $ds['hostname'] ?? '' }}"
                           style="width:280px" placeholder="api.klikniavolej.cz:80">
                </td>
            </tr>
            @endif
            <tr>
                <th>Uživatel</th>
                <td>
                    <input type="text" name="sms_user{{ $dId }}"
                           value="{{ $ds['user'] ?? '' }}"
                           style="width:200px">
                </td>
            </tr>
            <tr>
                <th>{{ $dId === 5 ? 'API klíč' : 'Heslo' }}</th>
                <td>
                    <input type="password" name="sms_password{{ $dId }}"
                           value="{{ $ds['password'] ?? '' }}"
                           style="width:300px">
                </td>
            </tr>
            @if($dCfg['has_test_mode'])
            <tr>
                <th>Testovací mód</th>
                <td>
                    <input type="checkbox" name="sms_test_mode{{ $dId }}" value="1"
                        {{ ($ds['test_mode'] ?? '0') == '1' ? 'checked' : '' }}>
                    <small style="color:#888;">SMS se nezasílají, pouze simulují odeslání.</small>
                </td>
            </tr>
            @endif
        </table>
        @endforeach

        <div style="margin-top:1.5em;">
            <button type="submit">Uložit nastavení SMS</button>
        </div>
    </form>
    @endif

@endsection
