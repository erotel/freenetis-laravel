<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>FreenetIS – Přihlášení</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; background: #f0f2f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .card { background: #fff; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,.15); width: 100%; max-width: 380px; }
        h1 { font-size: 1.4rem; margin-bottom: 1.5rem; color: #1a3a5c; text-align: center; }
        label { display: block; font-size: .875rem; margin-bottom: .25rem; color: #444; }
        input[type=text], input[type=password] { width: 100%; padding: .6rem .75rem; border: 1px solid #ccc; border-radius: 4px; font-size: 1rem; margin-bottom: 1rem; }
        input[type=text]:focus, input[type=password]:focus { outline: none; border-color: #1a3a5c; }
        .error { background: #fee; border: 1px solid #f99; border-radius: 4px; padding: .6rem .75rem; font-size: .875rem; color: #c00; margin-bottom: 1rem; }
        button { width: 100%; padding: .7rem; background: #1a3a5c; color: #fff; border: none; border-radius: 4px; font-size: 1rem; cursor: pointer; }
        button:hover { background: #14304e; }
        .remember { display: flex; align-items: center; gap: .4rem; margin-bottom: 1rem; font-size: .875rem; color: #444; }
    </style>
</head>
<body>
<div class="card">
    <h1>FreenetIS</h1>

    @if(session('success'))
        <div style="background:#d4edda; border:1px solid #c3e6cb; border-radius:4px; padding:.6rem .75rem; font-size:.875rem; color:#155724; margin-bottom:1rem;">
            {{ session('success') }}
        </div>
    @endif
    @if(session('error'))
        <div class="m-alert m-alert-danger">{{ session('error') }}</div>
    @endif
    @if ($errors->any())
        <div class="m-alert m-alert-danger">{{ $errors->first() }}</div>
    @endif

    <div id="wa-msg" class="error" style="display:none"></div>

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <label for="login">Uživatelské jméno</label>
        <input type="text" id="login" name="login" value="{{ old('login') }}" required autofocus autocomplete="username">

        <label for="password">Heslo</label>
        <input type="password" id="password" name="password" required autocomplete="current-password">

        <button type="submit">Přihlásit se heslem</button>

        <div id="wa-or" style="display:none;text-align:center;color:#888;font-size:.85rem;margin:.9rem 0 .6rem">— nebo —</div>
        <button type="button" id="wa-bio" style="display:none;background:#0a6b3c">🔒 Přihlásit biometrií</button>

        <div style="text-align:center; margin-top:0.75em; font-size:0.9em;">
            @if(\App\Models\Setting::get('forgotten_password', 0))
            <a href="{{ route('forgotten-password') }}">Zapomenuté heslo?</a>
            &nbsp;|&nbsp;
            @endif
            <a href="{{ route('registration.create') }}">Nemáte účet? Registrujte se</a>
        </div>
    </form>
</div>

@include('webauthn._scripts')
<script>
(function () {
    var bioBtn = document.getElementById('wa-bio');
    var loginInput = document.getElementById('login');
    var msg = document.getElementById('wa-msg');
    var prep = null, preparing = null, prepFor = '';
    var LS_KEY = 'fn-bio-last-login';

    function showMsg(t) { msg.textContent = t; msg.style.display = 'block'; }
    function rememberedLogin() {
        try { return localStorage.getItem(LS_KEY) || ''; } catch (e) { return ''; }
    }
    function rememberLogin(v) {
        try { v ? localStorage.setItem(LS_KEY, v) : localStorage.removeItem(LS_KEY); } catch (e) {}
    }
    // Po prefillu (níže) field nese remembered hodnotu. Když uživatel políčko
    // vymaže, znamená to "chci přihlásit jiného" → spadni na usernameless
    // (picker). Proto chooseLogin čte přímo z fieldu, ne z localStorage.
    function chooseLogin() {
        return (loginInput && loginInput.value || '').trim();
    }

    if (!FNWebAuthn.supported()) return;
    bioBtn.style.display = 'block';
    document.getElementById('wa-or').style.display = 'block';

    if (!loginInput.value) loginInput.value = rememberedLogin();

    FNWebAuthn.warmup();

    // Pokud máme login (uložený nebo vyplněný), předpřipravíme cílenou výzvu
    // (server vrátí jen klíče toho uživatele → platforma picker typicky
    // přeskočí). Jinak usernameless fallback (s pickerem).
    function preload() {
        var login = chooseLogin();
        prepFor = login;
        preparing = FNWebAuthn.prepareLogin(login).then(function (p) {
            // Cílená výzva pro neznámý login vrátí 422 → spadni na usernameless,
            // ať uživatel může pickerem zvolit jiný klíč.
            if (!p.ok && login) {
                prepFor = '';
                return FNWebAuthn.prepareLogin('').then(function (p2) { prep = p2; });
            }
            prep = p;
        }).catch(function () {});
    }
    preload();

    // Když uživatel přepíše login a neodpovídá tomu, na co je nachystaná výzva,
    // udělej preload znovu (jinak by stisk biometrie šel s starou cílenou výzvou).
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
            var r = await FNWebAuthn.finishLogin(p, 'web');
            if (r.expired) {
                var p2 = await FNWebAuthn.prepareLogin(chooseLogin());
                if (p2.ok) r = await FNWebAuthn.finishLogin(p2, 'web');
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
</body>
</html>
