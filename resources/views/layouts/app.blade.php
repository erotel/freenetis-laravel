@php
$popup = session('popup', false);
$userSettings = json_decode(auth()->user()?->settings ?? '{}', true);
$isDark = ($userSettings['dark_mode'] ?? 0) == 1;
@endphp
@if($popup)
    @yield('content')
@else
<!DOCTYPE html>
<html lang="cs" data-theme="{{ $isDark ? 'dark' : 'light' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title') | FreenetIS</title>

    <link rel="stylesheet" type="text/css" href="{{ asset('css/modern.css') }}">
    <style media="print">
    #fn-header,#fn-sidebar,#fn-hamburger,#sidebar-overlay{display:none!important}
    #fn-content{padding:0;background:#fff}
    a[href]::after{content:" ("attr(href)")"}
    </style>
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <style>
    /* ── CSS proměnné — světlý režim ── */
    :root{
        --fn-header-bg:#1a1a1a;
        --fn-sidebar-bg:#f9f8f6;
        --fn-sidebar-border:#e8e4de;
        --fn-sidebar-text:#444;
        --fn-sidebar-hover:#f0ede8;
        --fn-content-bg:#fff;
        --fn-body-bg:#f0ede8;
        --fn-text:#222;
        --fn-text-muted:#888;
        --fn-border:#e8e4de;
        --fn-card-bg:#fff;
        --fn-input-bg:#fff;
        --fn-table-hover:#fafafa;
        --fn-quote-bg:#f7f7f5;
    }
    [data-theme="dark"]{
        --fn-header-bg:#111;
        --fn-sidebar-bg:#1a1a1a;
        --fn-sidebar-border:#2a2a2a;
        --fn-sidebar-text:#ccc;
        --fn-sidebar-hover:#252525;
        --fn-content-bg:#141414;
        --fn-body-bg:#0d0d0d;
        --fn-text:#e0e0e0;
        --fn-text-muted:#888;
        --fn-border:#2a2a2a;
        --fn-card-bg:#1e1e1e;
        --fn-input-bg:#252525;
        --fn-table-hover:#1a1a1a;
        --fn-quote-bg:#2a2a2a;
    }

    /* ── Dark mode — sidebar background override ── */
    [data-theme="dark"] #fn-sidebar,
    [data-theme="dark"] #menu,
    [data-theme="dark"] #menu-padd{background:#1a1a1a !important}
    [data-theme="dark"] #fn-sidebar a,
    [data-theme="dark"] #menu a,
    [data-theme="dark"] #menu-padd a{color:#e0e0e0 !important}
    [data-theme="dark"] #fn-sidebar a:hover,
    [data-theme="dark"] #menu a:hover{color:#fff !important;background:#2a2a2a !important}

    /* ── Dark mode — m-* komponenty ── */
    [data-theme="dark"] .m-card{background:var(--fn-card-bg);border-color:var(--fn-border)}
    [data-theme="dark"] .m-table{background:var(--fn-card-bg)}
    [data-theme="dark"] .m-table th{color:var(--fn-text-muted);border-color:var(--fn-border)}
    [data-theme="dark"] .m-table td{border-color:var(--fn-border);color:var(--fn-text)}
    [data-theme="dark"] .m-table tr:hover td{background:var(--fn-table-hover)}
    [data-theme="dark"] .m-card .m-metric,
    [data-theme="dark"] div.m-metric{background:#2a2a2a !important;border:1px solid #333 !important}
    [data-theme="dark"] .m-metric-label{color:var(--fn-text-muted)}
    [data-theme="dark"] .m-metric-value{color:#e0e0e0 !important}
    [data-theme="dark"] .m-metric-value.green{color:#4ade80 !important}
    [data-theme="dark"] .m-metric-value.red{color:#f87171 !important}
    [data-theme="dark"] .m-field{border-color:var(--fn-border)}
    [data-theme="dark"] .m-field-label{color:var(--fn-text-muted)}
    [data-theme="dark"] .m-field-value{color:var(--fn-text)}
    [data-theme="dark"] .m-section{color:var(--fn-text-muted);border-color:var(--fn-border)}
    [data-theme="dark"] .m-btn{border-color:var(--fn-border);color:var(--fn-text-muted)}
    [data-theme="dark"] .m-btn:hover{background:var(--fn-table-hover);color:var(--fn-text)}
    [data-theme="dark"] .m-form-input,
    [data-theme="dark"] .m-form-select{background:var(--fn-input-bg);border-color:var(--fn-border);color:var(--fn-text)}
    [data-theme="dark"] .m-card-title{color:var(--fn-text-muted)}
    [data-theme="dark"] .m-subtitle{color:var(--fn-text-muted)}
    [data-theme="dark"] .m-user-name{color:var(--fn-text)}
    [data-theme="dark"] .m-user-login{color:var(--fn-text-muted)}
    [data-theme="dark"] .m-avatar{background:#1e3a5f;color:#85b7eb}
    [data-theme="dark"] .m-ip-addr{color:var(--fn-text)}
    [data-theme="dark"] input,[data-theme="dark"] select,[data-theme="dark"] textarea{background:var(--fn-input-bg) !important;border-color:var(--fn-border) !important;color:var(--fn-text) !important}
    [data-theme="dark"] h2,[data-theme="dark"] h3{color:var(--fn-text)}
    [data-theme="dark"] #breadcrumbs{color:var(--fn-text-muted)}
    [data-theme="dark"] #breadcrumbs a{color:var(--fn-text-muted)}
    [data-theme="dark"] .m-page a{color:#e8a96a}
    [data-theme="dark"] .m-page a:hover{color:#f0c080}
    [data-theme="dark"] .fn-page{background:#1e1e1e !important;border-color:#333 !important;color:#ccc !important}
    [data-theme="dark"] .fn-page:hover{background:#2a2a2a !important;color:#fff !important}
    [data-theme="dark"] .fn-page-active{background:#e8651a !important;border-color:#e8651a !important;color:#fff !important}
    [data-theme="dark"] .fn-page-disabled{background:#141414 !important;border-color:#222 !important;color:#555 !important}
    [data-theme="dark"] #fn-sidebar a{color:#ccc}
    [data-theme="dark"] #fn-sidebar a:hover{color:#fff;background:#2a2a2a}
    [data-theme="dark"] #fn-sidebar a.bold{color:#e8651a}
    [data-theme="dark"] #fn-sidebar .menu-group-label{color:#e8651a}
    [data-theme="dark"] #fn-sidebar a::before{color:#666;opacity:1}
    [data-theme="dark"] #fn-sidebar a:hover::before{color:#e8651a}

    /* ── Layout shell ── */
    html,body{margin:0;padding:0;background:var(--fn-body-bg)}
    #fn-wrap{display:flex;flex-direction:column;min-height:100vh;max-width:1400px;margin:0 auto}

    /* HEADER */
    #fn-header{
        display:flex;align-items:center;
        background:var(--fn-header-bg);
        height:52px;
        padding:0 20px;
        flex-shrink:0;
        position:sticky;top:0;z-index:100;
    }
    #fn-hamburger{
        display:none;
        align-items:center;justify-content:center;
        background:none;border:none;
        color:#fff;font-size:24px;
        cursor:pointer;padding:8px;
        margin-right:8px;
        line-height:1;
    }
    #fn-logo{
        font-size:24px;font-weight:700;letter-spacing:-.5px;
        color:#fff;text-decoration:none;white-space:nowrap;
        margin-right:24px;
    }
    #fn-logo em{color:#e8651a;font-style:normal}
    #fn-header-sep{flex:1}
    #fn-user{
        display:flex;align-items:center;gap:16px;
        font-size:16px;color:rgba(255,255,255,.65);
    }
    #fn-user strong{color:rgba(255,255,255,.9);font-weight:500}
    #fn-user .fn-ip{font-family:monospace;font-size:14px;color:rgba(255,255,255,.45)}
    #fn-logout-btn{
        font-size:14px;font-weight:500;
        color:#1a1a1a;background:#e8651a;
        border:none;cursor:pointer;
        padding:5px 14px;border-radius:20px;
        text-decoration:none;display:inline-block;
        transition:background .15s;
        white-space:nowrap;
    }
    #fn-logout-btn:hover{background:#d45a14;color:#fff}
    #dark-toggle{
        background:none;border:1px solid rgba(255,255,255,.25);border-radius:20px;
        color:rgba(255,255,255,.7);font-size:19px;cursor:pointer;
        padding:3px 9px;line-height:1;transition:border-color .15s,color .15s;
    }
    #dark-toggle:hover{border-color:rgba(255,255,255,.6);color:#fff}

    /* BODY ROW */
    #fn-body{display:flex;flex:1}

    /* SIDEBAR */
    #fn-sidebar{
        width:210px;flex-shrink:0;
        background:var(--fn-sidebar-bg);
        border-right:1px solid var(--fn-sidebar-border);
        display:flex;flex-direction:column;
        position:sticky;top:52px;
        height:calc(100vh - 52px);
        overflow-y:auto;
    }
    #fn-search-wrap{padding:12px 12px 8px;position:relative}
    #fn-search-wrap input[type=text]{
        width:100%;box-sizing:border-box;
        padding:7px 10px;font-size:16px;
        border:1px solid var(--fn-border);border-radius:6px;
        background:var(--fn-input-bg);color:var(--fn-text);outline:none;
        transition:border-color .15s,box-shadow .15s;
    }
    #fn-search-wrap input[type=text]:focus{
        border-color:#e8651a;
        box-shadow:0 0 0 3px rgba(232,101,26,.12);
    }
    #fn-whisper{
        position:absolute;top:100%;left:12px;right:12px;z-index:9999;
        background:var(--fn-card-bg);border:1px solid var(--fn-border);border-radius:6px;
        box-shadow:0 4px 12px rgba(0,0,0,.2);
        overflow:hidden;
    }
    #fn-sidebar-menu{flex:1;padding:4px 0 16px}

    /* OVERLAY */
    #sidebar-overlay{
        position:fixed;inset:0;
        background:rgba(0,0,0,.4);
        z-index:999;display:none;
    }
    #sidebar-overlay.visible{display:block}

    /* CONTENT */
    #fn-content{
        flex:1;
        background:var(--fn-content-bg);
        color:var(--fn-text);
        padding:20px 24px;
        min-width:0;
    }

    /* FOOTER */
    #fn-footer{
        background:var(--fn-body-bg);border-top:1px solid var(--fn-border);
        padding:8px 20px;
        font-size:14px;color:var(--fn-text-muted);
        flex-shrink:0;
    }
    #fn-footer a{color:var(--fn-text-muted);text-decoration:none}
    #fn-footer a:hover{color:var(--fn-text)}

    /* Breadcrumbs */
    #breadcrumbs{
        font-size:16px;color:var(--fn-text-muted);
        margin-bottom:14px;
    }
    #breadcrumbs a{color:var(--fn-text-muted);text-decoration:none}
    #breadcrumbs a:hover{color:var(--fn-text);text-decoration:underline}

    /* MOBILE */
    @media(max-width:760px){
        #fn-hamburger{display:flex}
        #fn-user .fn-ip{display:none}
        #fn-sidebar{
            position:fixed;
            left:-220px;
            top:52px;
            height:calc(100vh - 52px);
            z-index:1000;
            transition:left .25s ease;
            width:210px;
        }
        #fn-sidebar.open{left:0}
    }
    </style>

    @yield('styles')
</head>
<body>
<div id="fn-wrap">

    {{-- OVERLAY --}}
    <div id="sidebar-overlay"></div>

    {{-- HEADER --}}
    <header id="fn-header">
        @auth
        <button id="fn-hamburger" aria-label="Otevřít menu">&#9776;</button>
        @endauth
        <a href="{{ url('/') }}" id="fn-logo">Free<em>net</em>IS</a>
        <div id="fn-header-sep"></div>
        @auth
        <div id="fn-user">
            <span>
                <strong>{{ auth()->user()->name }} {{ auth()->user()->surname }}</strong>
                <span style="color:rgba(255,255,255,.45)"> / {{ auth()->user()->login }}</span>
            </span>
            <span class="fn-ip" id="user_ip_address">{{ request()->ip() }}</span>
            <a href="{{ route('webauthn.index') }}" id="dark-toggle" style="text-decoration:none" title="Biometrické přihlášení">🔑</a>
            <button id="dark-toggle" onclick="toggleDark()" title="Přepnout tmavý/světlý režim">
                {{ $isDark ? '☀️' : '🌙' }}
            </button>
            <form method="POST" action="{{ route('logout') }}" style="margin:0">
                @csrf
                <button type="submit" id="fn-logout-btn">Odhlásit se</button>
            </form>
        </div>
        @endauth
    </header>

    {{-- BODY --}}
    <div id="fn-body">

        {{-- SIDEBAR --}}
        <nav id="fn-sidebar">
            @auth
            <div id="fn-search-wrap">
                <form method="GET" action="{{ route('search') }}" autocomplete="off" id="search-form">
                    <input type="text" name="q" id="search-input"
                           value="{{ request('q') }}" placeholder="Hledat...">
                </form>
                <div id="fn-whisper" style="display:none"></div>
            </div>
            @endauth
            <div id="fn-sidebar-menu">
                @yield('menu')
            </div>
        </nav>

        {{-- CONTENT --}}
        <main id="fn-content">
            @if(session('success'))
            <div class="m-alert m-alert-success">{{ session('success') }}</div>
            @endif
            @if(session('error'))
            <div class="m-alert m-alert-danger">{{ session('error') }}</div>
            @endif
            @if(session('info'))
            <div class="m-alert m-alert-info">{{ session('info') }}</div>
            @endif
            @if(!empty($connectionRequestBanner) && !request()->is('connection-requests/create/*'))
            <div class="m-alert m-alert-info">
                Vaše IP adresa <strong>{{ $connectionRequestBanner['ip'] }}</strong> není registrována.
                <a href="{{ route('connection_requests.create', [$connectionRequestBanner['subnet_id'], $connectionRequestBanner['ip']]) }}">Zaregistrovat toto připojení</a>.
            </div>
            @endif
            @yield('breadcrumbs')
            @yield('content')
        </main>

    </div>

    {{-- FOOTER --}}
    <footer id="fn-footer">
        Powered by <a href="http://www.freenetis.org/">FreenetIS</a>
        <span style="color:var(--fn-text-muted);margin-left:6px">v{{ config('version.string') }}</span>
    </footer>

</div>

@auth
<script>
(function(){
    /* ── Search whisper ── */
    var timer=null;
    var input=document.getElementById('search-input');
    var whisper=document.getElementById('fn-whisper');
    if(input&&whisper){
        input.addEventListener('input',function(){
            clearTimeout(timer);var q=this.value.trim();
            if(q.length<3){whisper.style.display='none';return;}
            timer=setTimeout(function(){fetchResults(q);},400);
        });
        input.addEventListener('keydown',function(e){if(e.key==='Escape')whisper.style.display='none';});
        document.addEventListener('click',function(e){
            var w=document.getElementById('fn-search-wrap');
            if(w&&!w.contains(e.target))whisper.style.display='none';
        });
        function fetchResults(q){
            fetch('{{ route("search.ajax") }}?q='+encodeURIComponent(q))
                .then(function(r){return r.json();})
                .then(function(data){
                    if(!data.length){whisper.style.display='none';return;}
                    var html=data.map(function(item){
                        return '<a href="'+item.url+'" style="display:block;padding:7px 12px;text-decoration:none;color:#333;border-bottom:1px solid #f0f0f0;">'+
                            '<strong style="color:#e8651a;">'+highlight(item.title,q)+'</strong>'+
                            (item.detail?'<br><small style="color:#888;font-size:13px;">'+highlight(item.detail,q)+'</small>':'')+
                            '</a>';
                    }).join('');
                    html+='<a href="{{ route("search") }}?q='+encodeURIComponent(q)+'" style="display:block;padding:6px 12px;background:#f9f8f6;color:#888;text-decoration:none;font-size:14px;text-align:center;">Zobrazit všechny výsledky →</a>';
                    whisper.innerHTML=html;whisper.style.display='block';
                })
                .catch(function(){whisper.style.display='none';});
        }
        function highlight(text,q){
            if(!q||!text)return text;
            var e=q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&');
            return String(text).replace(new RegExp('('+e+')','gi'),'<span style="background:#fff3cd;font-weight:600;">$1</span>');
        }
    }

    /* ── Dark mode toggle ── */
    window.toggleDark=function(){
        var html=document.documentElement;
        var isDark=html.getAttribute('data-theme')==='dark';
        var newTheme=isDark?'light':'dark';
        html.setAttribute('data-theme',newTheme);
        var btn=document.getElementById('dark-toggle');
        if(btn)btn.textContent=isDark?'🌙':'☀️';
        fetch('{{ route("user.dark-mode") }}',{
            method:'POST',
            headers:{'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},
            body:JSON.stringify({dark_mode:isDark?0:1})
        });
    };

    /* ── Hamburger menu ── */
    var hamburger=document.getElementById('fn-hamburger');
    var sidebar=document.getElementById('fn-sidebar');
    var overlay=document.getElementById('sidebar-overlay');

    function openSidebar(){
        sidebar.classList.add('open');
        overlay.classList.add('visible');
        document.body.style.overflow='hidden';
    }
    function closeSidebar(){
        sidebar.classList.remove('open');
        overlay.classList.remove('visible');
        document.body.style.overflow='';
    }

    if(hamburger){
        hamburger.addEventListener('click',function(){
            sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
        });
    }
    if(overlay){
        overlay.addEventListener('click',closeSidebar);
    }
    /* Zavřít sidebar po kliku na odkaz (mobil) */
    if(sidebar){
        sidebar.querySelectorAll('a[href]').forEach(function(link){
            link.addEventListener('click',function(){
                if(window.innerWidth<=760) closeSidebar();
            });
        });
    }
})();
</script>
@endauth
</body>
</html>
@endif
