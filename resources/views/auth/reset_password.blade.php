<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreenetIS – Nové heslo</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; background: #f0f2f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .card { background: #fff; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,.15); width: 100%; max-width: 420px; }
        h1 { font-size: 1.4rem; margin-bottom: 1.5rem; color: #1a3a5c; text-align: center; }
        label { display: block; font-size: .875rem; margin-bottom: .25rem; color: #444; }
        input[type=password] { width: 100%; padding: .6rem .75rem; border: 1px solid #ccc; border-radius: 4px; font-size: 1rem; margin-bottom: 1rem; }
        input[type=password]:focus { outline: none; border-color: #1a3a5c; }
        .error { background: #fee; border: 1px solid #f99; border-radius: 4px; padding: .6rem .75rem; font-size: .875rem; color: #c00; margin-bottom: 1rem; }
        button { width: 100%; padding: .7rem; background: #1a3a5c; color: #fff; border: none; border-radius: 4px; font-size: 1rem; cursor: pointer; }
        button:hover { background: #14304e; }
    </style>
</head>
<body>
<div class="card">
    <h1>Nastavit nové heslo</h1>

    @if($errors->any())
        <div class="error">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('forgotten-password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <label for="password">Nové heslo</label>
        <input type="password" id="password" name="password" autofocus>
        <label for="password_confirmation">Heslo znovu</label>
        <input type="password" id="password_confirmation" name="password_confirmation">
        <button type="submit">Uložit nové heslo</button>
    </form>
</div>
</body>
</html>
