@extends('field.layout')

@section('title', 'Přihlášení')

@section('content')
<div class="f-card" style="margin-top:8vh">
    <h1 style="font-size:22px;margin:0 0 4px">Přihlášení</h1>
    <p style="color:var(--muted);margin:0 0 20px;font-size:15px">FreenetIS Field</p>

    <div id="wa-msg" class="f-alert f-alert-error" style="display:none"></div>

    <button type="button" id="wa-bio" class="f-btn" style="display:none;margin-bottom:18px">
        🔒 Přihlásit biometrií
    </button>

    <div id="wa-or" style="display:none;text-align:center;color:var(--muted);font-size:13px;margin-bottom:14px">— nebo heslem —</div>

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
    var prep = null, preparing = null;

    function showMsg(t) { msg.textContent = t; msg.style.display = 'block'; }

    if (!FNWebAuthn.supported()) return; // HTTP / nepodporováno → jen heslo
    bioBtn.style.display = 'block';
    document.getElementById('wa-or').style.display = 'block';

    FNWebAuthn.warmup(); // zahřej FIDO modul (Android) kvůli první výzvě
    // Přednačti výzvu hned → po kliknutí se get() zavolá okamžitě (kvůli iOS).
    function preload() { preparing = FNWebAuthn.prepareLogin('').then(function (p) { prep = p; }).catch(function () {}); }
    preload();

    bioBtn.addEventListener('click', async function () {
        msg.style.display = 'none';
        bioBtn.disabled = true;
        try {
            var p = prep;
            if (!p) { await preparing; p = prep; }
            if (!p || !p.ok) { showMsg((p && p.message) || 'Žádný passkey pro tuto doménu. Přihlas se heslem a zaregistruj zařízení.'); bioBtn.disabled = false; preload(); return; }
            prep = null;
            var r = await FNWebAuthn.finishLogin(p, 'field');
            if (r.expired) { var p2 = await FNWebAuthn.prepareLogin(''); if (p2.ok) r = await FNWebAuthn.finishLogin(p2, 'field'); }
            if (r.ok && r.redirect) { window.location = r.redirect; return; }
            showMsg('Přihlášení se nezdařilo.'); bioBtn.disabled = false; preload();
        } catch (e) {
            showMsg(e.message || 'Přihlášení se nezdařilo.'); bioBtn.disabled = false; preload();
        }
    });
})();
</script>
@endsection
