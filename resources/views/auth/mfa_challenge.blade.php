<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>FreenetIS – Ověření</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; background: #f0f2f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .card { background: #fff; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,.15); width: 100%; max-width: 380px; }
        h1 { font-size: 1.4rem; margin-bottom: .5rem; color: #1a3a5c; text-align: center; }
        p.sub { font-size: .9rem; color: #666; text-align: center; margin-bottom: 1.5rem; }
        label { display: block; font-size: .875rem; margin-bottom: .25rem; color: #444; }
        input[type=text] { width: 100%; padding: .6rem .75rem; border: 1px solid #ccc; border-radius: 4px; font-size: 1.4rem; letter-spacing: .3em; text-align: center; margin-bottom: 1rem; }
        input[type=text]:focus { outline: none; border-color: #1a3a5c; }
        .error { background: #fee; border: 1px solid #f99; border-radius: 4px; padding: .6rem .75rem; font-size: .875rem; color: #c00; margin-bottom: 1rem; }
        button { width: 100%; padding: .7rem; background: #1a3a5c; color: #fff; border: none; border-radius: 4px; font-size: 1rem; cursor: pointer; }
        button:hover { background: #14304e; }
        .links { text-align: center; margin-top: 1rem; font-size: .875rem; }
        .links a { color: #1a3a5c; cursor: pointer; }
    </style>
</head>
<body>
<div class="card">
    <h1>Ověření přihlášení</h1>
    <p class="sub" id="sub">Zadejte 6místný kód z aplikace v telefonu.</p>

    @if ($errors->any())
        <div class="error">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('mfa.challenge.verify') }}">
        @csrf
        <input type="hidden" name="mode" id="mode" value="totp">

        <label for="code" id="code-label">Kód z aplikace</label>
        <input type="text" id="code" name="code" required autofocus autocomplete="one-time-code"
               inputmode="text" placeholder="000000">

        <button type="submit">Ověřit a přihlásit</button>
    </form>

    <div class="links">
        <a id="toggle">Nemám telefon – použít záložní kód</a>
        <br><br>
        <a href="{{ route('login') }}">← Zpět na přihlášení</a>
    </div>
</div>

<script>
(function () {
    var mode = document.getElementById('mode');
    var label = document.getElementById('code-label');
    var sub = document.getElementById('sub');
    var input = document.getElementById('code');
    var toggle = document.getElementById('toggle');
    var recovery = false;
    toggle.addEventListener('click', function () {
        recovery = !recovery;
        mode.value = recovery ? 'recovery' : 'totp';
        if (recovery) {
            label.textContent = 'Záložní kód';
            sub.textContent = 'Zadejte jeden z jednorázových záložních kódů.';
            input.style.letterSpacing = '.1em';
            input.style.fontSize = '1.1rem';
            input.placeholder = 'XXXXX-XXXXX';
            toggle.textContent = 'Mám telefon – použít kód z aplikace';
        } else {
            label.textContent = 'Kód z aplikace';
            sub.textContent = 'Zadejte 6místný kód z aplikace v telefonu.';
            input.style.letterSpacing = '.3em';
            input.style.fontSize = '1.4rem';
            input.placeholder = '000000';
            toggle.textContent = 'Nemám telefon – použít záložní kód';
        }
        input.value = '';
        input.focus();
    });
})();
</script>
</body>
</html>
