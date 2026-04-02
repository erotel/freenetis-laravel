<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreenetIS – Dashboard</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f0f2f5; padding: 2rem; }
        .card { background: #fff; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,.15); max-width: 600px; margin: 0 auto; }
        h1 { color: #1a3a5c; margin-bottom: 1rem; }
        p { color: #444; margin-bottom: .5rem; }
        form { margin-top: 1.5rem; }
        button { padding: .5rem 1.2rem; background: #c0392b; color: #fff; border: none; border-radius: 4px; cursor: pointer; }
        button:hover { background: #a93226; }
        .badge { display: inline-block; background: #1a3a5c; color: #fff; border-radius: 4px; padding: .15rem .5rem; font-size: .8rem; }
    </style>
</head>
<body>
<div class="card">
    <h1>Vítejte, {{ auth()->user()->name }} {{ auth()->user()->surname }}</h1>
    <p>Přihlašovací jméno: <strong>{{ auth()->user()->login }}</strong></p>
    <p>Typ uživatele: <span class="badge">{{ auth()->user()->type == 1 ? 'Hlavní uživatel' : 'Uživatel' }}</span></p>
    <p>Member ID: {{ auth()->user()->member_id }}</p>

    @can('freenetis', ['view', 'Users_Controller', 'users'])
        <p style="margin-top:1rem; color: green;">✔ Máte právo prohlížet uživatele (view_all)</p>
    @endcan

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit">Odhlásit se</button>
    </form>
</div>
</body>
</html>
