@extends('field.layout')

@section('title', 'Přihlášení')

@section('content')
<div class="f-card" style="margin-top:8vh">
    <h1 style="font-size:22px;margin:0 0 4px">Přihlášení</h1>
    <p style="color:var(--muted);margin:0 0 20px;font-size:15px">FreenetIS Field</p>

    <div id="wa-msg" class="f-alert f-alert-error" style="display:none"></div>

    <form method="POST" action="{{ route('field.login') }}" autocomplete="on">
        @csrf
        <label style="display:block;margin-bottom:14px">
            <span style="font-size:13px;color:var(--muted);display:block;margin-bottom:5px">Uživatelské jméno</span>
            <input type="text" id="login" name="login" class="f-input" value="{{ old('login') }}"
                   autocapitalize="none" autocorrect="off" autofocus required
                   inputmode="text" autocomplete="username">
        </label>

        <button type="button" id="wa-bio" class="f-btn" style="display:none;margin-bottom:14px">
            🔒 Přihlásit biometrií
        </button>

        <label style="display:block;margin-bottom:20px">
            <span style="font-size:13px;color:var(--muted);display:block;margin-bottom:5px">Heslo</span>
            <input type="password" name="password" class="f-input" autocomplete="current-password">
        </label>
        <button type="submit" class="f-btn f-btn-ghost">Přihlásit se heslem</button>
    </form>
</div>
@endsection

@section('scripts')
@include('webauthn._scripts')
<script>
(function () {
    var bioBtn = document.getElementById('wa-bio');
    var msg = document.getElementById('wa-msg');
    var loginInput = document.getElementById('login');

    function showMsg(t) { msg.textContent = t; msg.style.display = 'block'; }

    if (!FNWebAuthn.supported()) return; // HTTP / nepodporováno → jen heslo
    bioBtn.style.display = 'block';

    bioBtn.addEventListener('click', async function () {
        msg.style.display = 'none';
        var login = (loginInput.value || '').trim();
        if (!login) { loginInput.focus(); showMsg('Nejdřív zadej uživatelské jméno.'); return; }
        bioBtn.disabled = true;
        try {
            var r = await FNWebAuthn.login(login, 'field');
            if (r.fallback) { showMsg('Pro tento účet není biometrie. Přihlas se heslem.'); bioBtn.disabled = false; return; }
            if (r.ok && r.redirect) { window.location = r.redirect; return; }
            showMsg('Přihlášení se nezdařilo.'); bioBtn.disabled = false;
        } catch (e) {
            showMsg(e.message || 'Přihlášení se nezdařilo.'); bioBtn.disabled = false;
        }
    });
})();
</script>
@endsection
