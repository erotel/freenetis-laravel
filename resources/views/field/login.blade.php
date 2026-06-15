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
                   autocapitalize="none" autocorrect="off" required
                   inputmode="text" autocomplete="username">
        </label>
        <label style="display:block;margin-bottom:20px">
            <span style="font-size:13px;color:var(--muted);display:block;margin-bottom:5px">Heslo</span>
            <input type="password" name="password" class="f-input" required autocomplete="current-password">
        </label>
        <button type="submit" class="f-btn">Přihlásit se heslem</button>
    </form>

    <div id="wa-or" style="display:none;text-align:center;color:var(--muted);font-size:13px;margin:16px 0 12px">— nebo —</div>
    <button type="button" id="wa-bio" class="f-btn f-btn-ghost" style="display:none">
        🔒 Přihlásit biometrií
    </button>
</div>
@endsection

@section('scripts')
@include('webauthn._scripts')
<script>
(function () {
    var bioBtn = document.getElementById('wa-bio');
    var loginInput = document.getElementById('login');
    var msg = document.getElementById('wa-msg');
    var prep = null, preparing = null, prepFor = '';
    var LS_KEY = 'fn-bio-last-login'; // sdíleno s /login (stejná doména/uživatel)

    function showMsg(t) { msg.textContent = t; msg.style.display = 'block'; }
    function rememberedLogin() {
        try { return localStorage.getItem(LS_KEY) || ''; } catch (e) { return ''; }
    }
    function rememberLogin(v) {
        try { v ? localStorage.setItem(LS_KEY, v) : localStorage.removeItem(LS_KEY); } catch (e) {}
    }
    function chooseLogin() {
        return (loginInput && loginInput.value || '').trim();
    }

    if (!FNWebAuthn.supported()) return; // HTTP / nepodporováno → jen heslo
    bioBtn.style.display = 'block';
    document.getElementById('wa-or').style.display = 'block';

    if (!loginInput.value) loginInput.value = rememberedLogin();

    FNWebAuthn.warmup();

    // S loginem (uloženým/vyplněným) → cílená výzva → server vrátí klíče jen
    // pro toho uživatele → platforma picker typicky přeskočí.
    // Bez loginu → usernameless (picker).
    function preload() {
        var login = chooseLogin();
        prepFor = login;
        preparing = FNWebAuthn.prepareLogin(login).then(function (p) {
            if (!p.ok && login) {
                prepFor = '';
                return FNWebAuthn.prepareLogin('').then(function (p2) { prep = p2; });
            }
            prep = p;
        }).catch(function () {});
    }
    preload();

    var debounce;
    loginInput.addEventListener('input', function () {
        clearTimeout(debounce);
        debounce = setTimeout(function () {
            if (chooseLogin() !== prepFor) preload();
        }, 250);
    });

    bioBtn.addEventListener('click', async function () {
        msg.style.display = 'none';
        bioBtn.disabled = true;
        try {
            if (chooseLogin() !== prepFor) preload();
            var p = prep;
            if (!p) { await preparing; p = prep; }
            if (!p || !p.ok) { showMsg((p && p.message) || 'Žádný passkey pro tuto doménu. Přihlas se heslem a zaregistruj zařízení.'); bioBtn.disabled = false; preload(); return; }
            prep = null;
            var r = await FNWebAuthn.finishLogin(p, 'field');
            if (r.expired) {
                var p2 = await FNWebAuthn.prepareLogin(chooseLogin());
                if (p2.ok) r = await FNWebAuthn.finishLogin(p2, 'field');
            }
            if (r.ok && r.redirect) {
                var typed = (loginInput && loginInput.value || '').trim();
                rememberLogin(r.login || typed);
                window.location = r.redirect;
                return;
            }
            showMsg('Přihlášení se nezdařilo.'); bioBtn.disabled = false; preload();
        } catch (e) {
            showMsg(e.message || 'Přihlášení se nezdařilo.'); bioBtn.disabled = false; preload();
        }
    });
})();
</script>
@endsection
