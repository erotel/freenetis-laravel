<!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>{{ $has_message ? 'Připojení omezeno' : 'Informace o připojení' }} — {{ $support['org_name'] }}</title>
<style>
* { box-sizing: border-box; }
body {
    margin: 0;
    background: #f0ede8;
    color: #222;
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
    line-height: 1.5;
    padding: 24px 12px;
}
.wrap { max-width: 720px; margin: 0 auto; }
.card {
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,.07);
    padding: 24px 28px;
    margin-bottom: 16px;
}
.logo { text-align: center; margin-bottom: 20px; }
.logo img { max-height: 72px; }
.logo .placeholder {
    display: inline-block; padding: 12px 20px;
    background: #185FA5; color: #fff; font-weight: 700;
    border-radius: 6px; font-size: 22px;
}
h1 { margin: 0 0 18px; font-size: 26px; }
h1.alert { color: #b91c1c; }
h2 { font-size: 18px; margin: 20px 0 10px; color: #555; text-transform: uppercase; letter-spacing: .03em; }
.message-text { font-size: 18px; }
.message-text p:first-child { margin-top: 0; }
.message-text p:last-child { margin-bottom: 0; }
.comment {
    margin-top: 16px; padding: 10px 14px;
    background: #fef3c7; border: 1px solid #fcd34d; border-radius: 6px;
    font-size: 16px; color: #92400e;
}
.kv { display: grid; grid-template-columns: 160px 1fr; gap: 6px 14px; font-size: 17px; }
.kv .k { color: #888; }
.kv .v { font-weight: 600; }
.bad { color: #b91c1c; }
.support {
    background: #eef5fc; border: 1px solid #c8dcf3; border-radius: 6px;
    padding: 14px 18px; font-size: 17px;
}
.support a { color: #185FA5; }
.muted { color: #888; font-size: 14px; text-align: center; margin-top: 14px; }
@media (max-width: 480px) {
    .kv { grid-template-columns: 1fr; }
    .kv .k { margin-top: 6px; }
}
</style>
</head>
<body>
<div class="wrap">

<div class="logo">
    @if(file_exists(public_path('img/logo.png')))
        <img src="{{ asset('img/logo.png') }}" alt="{{ $support['org_name'] }}">
    @else
        <span class="placeholder">{{ $support['org_name'] }}</span>
    @endif
</div>

@if($has_message)

<div class="card">
    <h1 class="alert">Připojení omezeno</h1>

    <div class="message-text">
        {!! $message_text !!}
    </div>

    @if(!empty($comment))
    <div class="comment">
        <strong>Poznámka správce:</strong> {{ $comment }}
    </div>
    @endif
</div>

@if($member)
<div class="card">
    <h2>Identifikace</h2>
    <div class="kv">
        <div class="k">Jméno</div>
        <div class="v">{{ $member->name }}</div>

        <div class="k">Variabilní symbol</div>
        <div class="v" style="font-family:monospace">{{ $variable_symbol ?: $member->id }}</div>

        @if($expiration_date)
        @php
            $expExpired = false;
            try {
                $expExpired = \Carbon\Carbon::parse($expiration_date)
                    ->setTimezone('Europe/Prague')
                    ->lt(\Carbon\Carbon::now('Europe/Prague')->startOfDay());
            } catch (\Throwable $e) {}
            $expFmt = '';
            try { $expFmt = \Carbon\Carbon::parse($expiration_date)->format('d.m.Y'); }
            catch (\Throwable $e) { $expFmt = $expiration_date; }
        @endphp
        <div class="k">Zaplaceno do</div>
        <div class="v {{ $expExpired ? 'bad' : '' }}">{{ $expFmt }}</div>
        @endif

        @if($balance !== null)
        <div class="k">Aktuální saldo</div>
        <div class="v {{ $balance < 0 ? 'bad' : '' }}">{{ number_format($balance, 2, ',', ' ') }} Kč</div>
        @endif
    </div>
</div>
@endif

@else

<div class="card">
    <h1>Vaše připojení je v pořádku</h1>
    <p>Pravděpodobně jste se sem dostali omylem — pro Vaši IP adresu nemáme žádné aktivní omezení.</p>
    <p>Pokud jste sem byli přesměrováni a tato stránka se zobrazila, zkuste obnovit původní adresu nebo se obraťte na podporu.</p>
</div>

@endif

<div class="card support">
    <h2 style="margin-top:0">Kontakt na podporu</h2>
    @if(!empty($support['phone']))
    <div>Telefon: <a href="tel:{{ preg_replace('/\s+/', '', $support['phone']) }}"><strong>{{ $support['phone'] }}</strong></a></div>
    @endif
    @if(!empty($support['email']))
    <div>E-mail: <a href="mailto:{{ $support['email'] }}"><strong>{{ $support['email'] }}</strong></a></div>
    @endif
</div>

<div class="muted">Detekovaná IP: <code>{{ $client_ip }}</code></div>

</div>
</body>
</html>
