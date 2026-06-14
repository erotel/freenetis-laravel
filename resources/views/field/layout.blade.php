@php
    $userSettings = json_decode(auth()->user()?->settings ?? '{}', true) ?: [];
    $isDark = ($userSettings['dark_mode'] ?? 0) == 1;
@endphp
<!DOCTYPE html>
<html lang="cs" data-theme="{{ $isDark ? 'dark' : 'light' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#ff6b00">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="manifest" href="{{ route('field.manifest') }}">
    <link rel="apple-touch-icon" href="{{ route('field.icon', 192) }}">
    <title>@yield('title', 'Field') · FreenetIS</title>
    <style>
    :root{
        --bg:#f0ede8; --card:#fff; --text:#1a1a1a; --muted:#777; --border:#e2ddd5;
        --header:#1a1a1a; --accent:#ff6b00; --accent-dk:#d45a14;
        --ok:#22c55e; --overdue:#ef4444; --suspended:#9ca3af;
        --green:#16a34a; --red:#dc2626; --input:#fff;
    }
    [data-theme="dark"]{
        --bg:#0d0d0d; --card:#1c1c1c; --text:#e6e6e6; --muted:#888; --border:#2c2c2c;
        --header:#111; --input:#242424;
    }
    *{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
    html,body{margin:0;padding:0}
    body{
        background:var(--bg);color:var(--text);
        font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
        font-size:16px;line-height:1.45;
        -webkit-text-size-adjust:100%;
        padding-bottom:env(safe-area-inset-bottom);
        min-height:100vh;
    }
    a{color:var(--accent);text-decoration:none}

    /* HEADER */
    #f-header{
        position:sticky;top:0;z-index:50;
        display:flex;align-items:center;gap:12px;
        background:var(--header);color:#fff;
        padding:calc(10px + env(safe-area-inset-top)) 16px 10px;
        height:calc(54px + env(safe-area-inset-top));
    }
    #f-logo{font-size:20px;font-weight:800;letter-spacing:-.5px;color:#fff}
    #f-logo em{color:var(--accent);font-style:normal}
    #f-header .spacer{flex:1}
    #f-user{font-size:13px;color:rgba(255,255,255,.6);max-width:38vw;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;text-align:right}
    #f-logout{
        display:flex;align-items:center;justify-content:center;
        width:44px;height:44px;margin:-8px -8px -8px 0;
        background:none;border:none;color:rgba(255,255,255,.85);cursor:pointer;
        font-size:22px;
    }
    #f-logout:active{color:#fff}

    /* CONTENT */
    #f-main{max-width:700px;margin:0 auto;padding:16px;width:100%}

    /* ALERTS */
    .f-alert{padding:12px 14px;border-radius:10px;margin-bottom:14px;font-size:15px}
    .f-alert-success{background:rgba(34,197,94,.14);color:var(--green);border:1px solid rgba(34,197,94,.3)}
    .f-alert-error{background:rgba(239,68,68,.14);color:var(--red);border:1px solid rgba(239,68,68,.3)}

    /* CARDS / SECTIONS */
    .f-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:16px;margin-bottom:14px}
    .f-section-title{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin:0 0 10px}

    /* STATUS DOT */
    .f-dot{display:inline-block;width:12px;height:12px;border-radius:50%;flex-shrink:0}
    .f-dot.ok{background:var(--ok)}
    .f-dot.overdue{background:var(--overdue)}
    .f-dot.suspended{background:var(--suspended)}

    /* ROWS (tappable) */
    .f-row{
        display:flex;align-items:center;gap:14px;
        min-height:54px;padding:10px 4px;
        border-bottom:1px solid var(--border);
        color:var(--text);
    }
    .f-row:last-child{border-bottom:none}
    .f-row:active{background:var(--bg)}
    .f-row .f-row-main{flex:1;min-width:0}
    .f-row .f-row-title{font-weight:600;font-size:16px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .f-row .f-row-sub{font-size:13px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .f-row .f-chevron{color:var(--muted);font-size:20px}

    /* CONTACT/ACTION LINKS (min 44px touch) */
    .f-link{
        display:flex;align-items:center;gap:12px;
        min-height:48px;padding:8px 4px;
        border-bottom:1px solid var(--border);color:var(--text);font-size:16px;
    }
    .f-link:last-child{border-bottom:none}
    .f-link:active{background:var(--bg)}
    .f-link .f-ic{width:26px;text-align:center;font-size:18px;flex-shrink:0}
    .f-link > span:last-child{min-width:0;overflow-wrap:anywhere}

    /* KEY-VALUE */
    .f-kv{display:flex;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px solid var(--border);font-size:15px}
    .f-kv:last-child{border-bottom:none}
    .f-kv .k{color:var(--muted);min-width:0;overflow-wrap:anywhere}
    .f-kv .v{font-weight:600;text-align:right;min-width:0;overflow-wrap:anywhere}
    .v.green{color:var(--green)} .v.red{color:var(--red)}

    /* BUTTONS */
    .f-btn{
        display:flex;align-items:center;justify-content:center;gap:8px;
        width:100%;min-height:50px;padding:12px;
        background:var(--accent);color:#fff;border:none;border-radius:12px;
        font-size:16px;font-weight:600;cursor:pointer;
    }
    .f-btn:active{background:var(--accent-dk)}
    .f-btn-ghost{background:transparent;color:var(--text);border:1px solid var(--border)}
    .f-btn-ghost:active{background:var(--bg)}

    /* INPUTS */
    .f-input,.f-textarea{
        width:100%;padding:13px 14px;font-size:16px;
        background:var(--input);color:var(--text);
        border:1px solid var(--border);border-radius:12px;outline:none;
    }
    .f-input:focus,.f-textarea:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(255,107,0,.15)}
    .f-textarea{resize:vertical;min-height:90px;font-family:inherit}

    /* INTERFACE blok — jméno / IP / MAC každé na vlastním řádku (min. zalamování) */
    .f-iface{padding:10px 0;border-bottom:1px solid var(--border)}
    .f-iface:last-child{border-bottom:none}
    .f-iface-name{font-weight:600;font-size:15px;margin-bottom:3px}
    .f-iface-line{font-family:monospace;font-size:13px;line-height:1.6;overflow-wrap:anywhere}
    .f-iface-line.muted{color:var(--muted)}
    .f-iface-line .tag{display:inline-block;min-width:38px;color:var(--muted);font-family:inherit}

    .f-empty{color:var(--muted);font-size:15px;text-align:center;padding:24px 0}
    .f-back{display:inline-flex;align-items:center;gap:6px;min-height:44px;color:var(--muted);font-size:15px}
    </style>
    @yield('head')
</head>
<body>
    <header id="f-header">
        <a href="{{ route('field.search') }}" id="f-logo">Free<em>net</em>IS</a>
        <span class="spacer"></span>
        @auth
        <span id="f-user">{{ auth()->user()->name }} {{ auth()->user()->surname }}</span>
        <form method="POST" action="{{ route('field.logout') }}" style="margin:0">
            @csrf
            <button type="submit" id="f-logout" title="Odhlásit se" aria-label="Odhlásit se">⏻</button>
        </form>
        @endauth
    </header>

    <main id="f-main">
        @if(session('success'))<div class="f-alert f-alert-success">{{ session('success') }}</div>@endif
        @if(session('error'))<div class="f-alert f-alert-error">{{ session('error') }}</div>@endif
        @if(isset($errors) && $errors->any())<div class="f-alert f-alert-error">{{ $errors->first() }}</div>@endif
        @yield('content')
    </main>

    <script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('{{ route('field.sw') }}').catch(function(){});
        });
    }
    </script>
    @yield('scripts')
</body>
</html>
