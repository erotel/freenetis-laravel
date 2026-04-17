@php $popup = session('popup', false); @endphp
@if($popup)
    @yield('content')
@else
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title') | FreenetIS</title>

    <link rel="stylesheet" type="text/css" href="{{ asset('media/css/style.css') }}">
    <link rel="stylesheet" type="text/css" href="{{ asset('media/css/tables.css') }}">
    <link rel="stylesheet" type="text/css" href="{{ asset('media/css/forms.css') }}">
    <link rel="stylesheet" type="text/css" href="{{ asset('media/css/jquery-ui.css') }}">
    <link rel="stylesheet" type="text/css" href="{{ asset('media/css/jquery.validate.password.css') }}">
    <link rel="stylesheet" type="text/css" href="{{ asset('media/css/modern.css') }}">
    <link rel="stylesheet" type="text/css" media="print" href="{{ asset('media/css/print.css') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <script type="text/javascript" src="{{ asset('media/js/jquery.min.js') }}"></script>
    <script type="text/javascript" src="{{ asset('media/js/jquery-ui.min.js') }}"></script>
    <script type="text/javascript" src="{{ asset('media/js/jquery.ui.datepicker-cs.js') }}"></script>
    <script type="text/javascript" src="{{ asset('media/js/jquery.validate.min.js') }}"></script>
    <script type="text/javascript" src="{{ asset('media/js/jquery.validate.password.js') }}"></script>
    <script type="text/javascript" src="{{ asset('media/js/messages_cs.js') }}"></script>
    <script type="text/javascript" src="{{ asset('media/js/php.min.js') }}"></script>

    <style>
    /* ── Layout shell ── */
    html,body{margin:0;padding:0;background:#f0ede8}
    #fn-wrap{display:flex;flex-direction:column;min-height:100vh;max-width:1400px;margin:0 auto}

    /* HEADER */
    #fn-header{
        display:flex;align-items:center;
        background:#1a1a1a;
        height:52px;
        padding:0 20px;
        flex-shrink:0;
        position:sticky;top:0;z-index:100;
    }
    #fn-hamburger{
        display:none;
        align-items:center;justify-content:center;
        background:none;border:none;
        color:#fff;font-size:20px;
        cursor:pointer;padding:8px;
        margin-right:8px;
        line-height:1;
    }
    #fn-logo{
        font-size:20px;font-weight:700;letter-spacing:-.5px;
        color:#fff;text-decoration:none;white-space:nowrap;
        margin-right:24px;
    }
    #fn-logo em{color:#e8651a;font-style:normal}
    #fn-header-sep{flex:1}
    #fn-user{
        display:flex;align-items:center;gap:16px;
        font-size:13px;color:rgba(255,255,255,.65);
    }
    #fn-user strong{color:rgba(255,255,255,.9);font-weight:500}
    #fn-user .fn-ip{font-family:monospace;font-size:12px;color:rgba(255,255,255,.45)}
    #fn-logout-btn{
        font-size:12px;font-weight:500;
        color:#1a1a1a;background:#e8651a;
        border:none;cursor:pointer;
        padding:5px 14px;border-radius:20px;
        text-decoration:none;display:inline-block;
        transition:background .15s;
        white-space:nowrap;
    }
    #fn-logout-btn:hover{background:#d45a14;color:#fff}

    /* BODY ROW — přirozený page scroll, sidebar sticky */
    #fn-body{display:flex;flex:1}

    /* SIDEBAR — sticky desktop, fixed mobile */
    #fn-sidebar{
        width:210px;flex-shrink:0;
        background:#f9f8f6;
        border-right:1px solid #e8e4de;
        display:flex;flex-direction:column;
        position:sticky;top:52px;
        height:calc(100vh - 52px);
        overflow-y:auto;
    }
    #fn-search-wrap{padding:12px 12px 8px;position:relative}
    #fn-search-wrap input[type=text]{
        width:100%;box-sizing:border-box;
        padding:7px 10px;font-size:13px;
        border:1px solid #ddd;border-radius:6px;
        background:#fff;color:#222;outline:none;
        transition:border-color .15s,box-shadow .15s;
    }
    #fn-search-wrap input[type=text]:focus{
        border-color:#e8651a;
        box-shadow:0 0 0 3px rgba(232,101,26,.12);
    }
    #fn-whisper{
        position:absolute;top:100%;left:12px;right:12px;z-index:9999;
        background:#fff;border:1px solid #ddd;border-radius:6px;
        box-shadow:0 4px 12px rgba(0,0,0,.12);
        overflow:hidden;
    }
    #fn-sidebar-menu{flex:1;padding:4px 0 16px}

    /* OVERLAY */
    #sidebar-overlay{
        position:fixed;inset:0;
        background:rgba(0,0,0,.4);
        z-index:999;
        display:none;
    }
    #sidebar-overlay.visible{display:block}

    /* CONTENT */
    #fn-content{
        flex:1;
        background:#fff;
        padding:20px 24px;
        min-width:0;
    }

    /* FOOTER */
    #fn-footer{
        background:#f0ede8;border-top:1px solid #e8e4de;
        padding:8px 20px;
        font-size:12px;color:#aaa;
        flex-shrink:0;
    }
    #fn-footer a{color:#aaa;text-decoration:none}
    #fn-footer a:hover{color:#666}

    /* Breadcrumbs */
    #breadcrumbs{
        font-size:13px;color:#999;
        margin-bottom:14px;
    }
    #breadcrumbs a{color:#777;text-decoration:none}
    #breadcrumbs a:hover{color:#333;text-decoration:underline}

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
            /* přepíšeme sticky z desktopu */
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
                            (item.detail?'<br><small style="color:#888;font-size:11px;">'+highlight(item.detail,q)+'</small>':'')+
                            '</a>';
                    }).join('');
                    html+='<a href="{{ route("search") }}?q='+encodeURIComponent(q)+'" style="display:block;padding:6px 12px;background:#f9f8f6;color:#888;text-decoration:none;font-size:12px;text-align:center;">Zobrazit všechny výsledky →</a>';
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
