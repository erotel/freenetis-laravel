@extends('layouts.app')
@section('title', 'Nastavení')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
    <div id="breadcrumbs">Nastavení</div>
@endsection
@section('content')
    <h2>Nastavení</h2>

    @if(session('success'))
        <div class="message success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="message error">{{ session('error') }}</div>
    @endif

    {{-- Tabs --}}
    <div style="margin-bottom:1em; border-bottom:2px solid #c00;">
        <a href="{{ route('settings.index', ['tab' => 'banka']) }}"
           style="display:inline-block; padding:6px 16px; margin-right:4px; text-decoration:none;
                  {{ $activeTab === 'banka' ? 'background:#c00; color:#fff; font-weight:bold;' : 'background:#eee; color:#333;' }}">
            Banka
        </a>
        <a href="{{ route('settings.index', ['tab' => 'email']) }}"
           style="display:inline-block; padding:6px 16px; text-decoration:none;
                  {{ $activeTab === 'email' ? 'background:#c00; color:#fff; font-weight:bold;' : 'background:#eee; color:#333;' }}">
            Email
        </a>
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
            Pokud předmět emailu <strong>začíná</strong> zadaným textem, odešle se kopie na zadanou adresu.
        </p>
        <table class="extended" cellspacing="0">
            <thead>
                <tr>
                    <th>Předmět začíná na</th>
                    <th>BCC adresa</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="bcc-rules">
                @foreach($bccRules as $i => $rule)
                    <tr>
                        <td><input type="text" name="bcc_subject[]" value="{{ $rule['subject'] }}" style="width:200px" placeholder="např. Faktura "></td>
                        <td><input type="text" name="bcc_address[]" value="{{ $rule['address'] }}" style="width:200px" placeholder="kopie@example.com"></td>
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
    function addBccRow() {
        const tbody = document.getElementById('bcc-rules');
        const tr = document.createElement('tr');
        tr.innerHTML = '<td><input type="text" name="bcc_subject[]" style="width:200px" placeholder="např. Faktura "></td>' +
                       '<td><input type="text" name="bcc_address[]" style="width:200px" placeholder="kopie@example.com"></td>' +
                       '<td><button type="button" onclick="this.closest(\'tr\').remove()">✕</button></td>';
        tbody.appendChild(tr);
    }
    </script>
    @endif

@endsection
