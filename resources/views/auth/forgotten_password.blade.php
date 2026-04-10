<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreenetIS – Zapomenuté heslo</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; background: #f0f2f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .card { background: #fff; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,.15); width: 100%; max-width: 420px; }
        h1 { font-size: 1.4rem; margin-bottom: 1.5rem; color: #1a3a5c; text-align: center; }
        label { display: block; font-size: .875rem; margin-bottom: .25rem; color: #444; }
        input[type=text] { width: 100%; padding: .6rem .75rem; border: 1px solid #ccc; border-radius: 4px; font-size: 1rem; margin-bottom: 1rem; }
        input[type=text]:focus { outline: none; border-color: #1a3a5c; }
        .error { background: #fee; border: 1px solid #f99; border-radius: 4px; padding: .6rem .75rem; font-size: .875rem; color: #c00; margin-bottom: 1rem; }
        button { width: 100%; padding: .7rem; background: #1a3a5c; color: #fff; border: none; border-radius: 4px; font-size: 1rem; cursor: pointer; }
        button:hover { background: #14304e; }
        .hint { color: #666; font-size: .875rem; margin-bottom: 1.25rem; }
        .back { text-align: center; margin-top: 1em; font-size: .9em; }
    </style>
</head>
<body>
<div class="card">
    <h1>Zapomenuté heslo</h1>

    @if(session('success'))
        <div style="background:#d4edda; border:1px solid #c3e6cb; padding:12px; margin-bottom:1em; border-radius:4px; color:#155724; font-size:.875rem;">
            {{ session('success') }}
        </div>
    @endif
    @if(session('error'))
        <div style="background:#f8d7da; border:1px solid #f5c6cb; padding:12px; margin-bottom:1em; border-radius:4px; color:#721c24; font-size:.875rem;">
            {{ session('error') }}
        </div>
    @endif
    @if($errors->any())
        <div class="error">{{ $errors->first() }}</div>
    @endif

    <p class="hint">Zadejte váš login, e-mail nebo variabilní symbol. Pošleme vám odkaz pro reset hesla.</p>

    <form method="POST" action="{{ route('forgotten-password.store') }}">
        @csrf
        <label for="login">Login / E-mail / Variabilní symbol</label>
        <input type="text" id="login" name="login" value="{{ old('login') }}" autofocus>
        <button type="submit">Odeslat odkaz pro reset</button>
    </form>

    <div class="back">
        <a href="{{ route('login') }}">← Zpět na přihlášení</a>
    </div>
</div>
</body>
</html>
