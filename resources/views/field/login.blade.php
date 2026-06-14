@extends('field.layout')

@section('title', 'Přihlášení')

@section('content')
<div class="f-card" style="margin-top:8vh">
    <h1 style="font-size:22px;margin:0 0 4px">Přihlášení</h1>
    <p style="color:var(--muted);margin:0 0 20px;font-size:15px">FreenetIS Field</p>

    <form method="POST" action="{{ route('field.login') }}" autocomplete="on">
        @csrf
        <label style="display:block;margin-bottom:14px">
            <span style="font-size:13px;color:var(--muted);display:block;margin-bottom:5px">Uživatelské jméno</span>
            <input type="text" name="login" class="f-input" value="{{ old('login') }}"
                   autocapitalize="none" autocorrect="off" autofocus required
                   inputmode="text" autocomplete="username">
        </label>
        <label style="display:block;margin-bottom:20px">
            <span style="font-size:13px;color:var(--muted);display:block;margin-bottom:5px">Heslo</span>
            <input type="password" name="password" class="f-input" required autocomplete="current-password">
        </label>
        <button type="submit" class="f-btn">Přihlásit se</button>
    </form>
</div>
@endsection
