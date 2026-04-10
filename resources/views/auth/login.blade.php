<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
        <div class="error">{{ session('error') }}</div>
    @endif
    @if ($errors->any())
        <div class="error">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <label for="login">Uživatelské jméno</label>
        <input type="text" id="login" name="login" value="{{ old('login') }}" required autofocus>

        <label for="password">Heslo</label>
        <input type="password" id="password" name="password" required>

        <div class="remember">
            <input type="checkbox" id="remember" name="remember">
            <label for="remember" style="margin:0">Zapamatovat si mě</label>
        </div>

        <button type="submit">Přihlásit se</button>
        <div style="text-align:center; margin-top:0.75em; font-size:0.9em;">
            @if(\App\Models\Setting::get('forgotten_password', 0))
            <a href="{{ route('forgotten-password') }}">Zapomenuté heslo?</a>
            &nbsp;|&nbsp;
            @endif
            <a href="{{ route('registration.create') }}">Nemáte účet? Registrujte se</a>
        </div>
    </form>
</div>
</body>
</html>
