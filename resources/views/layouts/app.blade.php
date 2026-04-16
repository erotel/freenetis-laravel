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

    @yield('styles')
</head>
<body>
<div id="main">

    <div id="header">
        <div id="cellphone_show_menu"></div>
        <div id="cellphone_menu_tooltip">Klikněte na logo pro otevření menu</div>
        <a href="{{ url('/') }}"><h1 id="logo"><span>Free<em>net</em>IS</span></h1></a>
        <div class="separator1"></div>
        @auth
        <div class="status">
            <table>
                <tr>
                    <td class="orange cellphone_hide">Jméno:</td>
                    <td class="bold">&nbsp;{{ auth()->user()->name }} {{ auth()->user()->surname }}&nbsp;({{ auth()->user()->login }})</td>
                </tr>
                <tr>
                    <td class="orange cellphone_hide">IP adresa:</td>
                    <td class="bold"><div id="user_ip_address">&nbsp;{{ request()->ip() }}</div></td>
                </tr>
            </table>
        </div>
        <div class="logout">
            <div>
                <form method="POST" action="{{ route('logout') }}" style="display:inline;">
                    @csrf
                    <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;color:inherit;text-decoration:underline;">Odhlásit se</button>
                </form>
            </div>
        </div>
        @endauth
    </div>

    <div id="middle">
        <div id="menu">
            <div id="cellphone_hide_menu"></div>
            <div id="menu-padd">
                @auth
                <div id="search-wrapper" style="position:relative;">
                    <form method="GET" action="{{ route('search') }}" class="search" autocomplete="off" id="search-form">
                        <input type="text" name="q" id="search-input" value="{{ request('q') }}">
                        <input type="image" id="search_submit" src="{{ asset('media/images/layout/search.gif') }}" alt="Hledat">
                    </form>
                    <div id="whisper" style="position:absolute;top:100%;left:0;z-index:9999;"></div>
                </div>
                @endauth
                @yield('menu')
            </div>
            <div class="clear"></div>
        </div>

        <div id="content">
            <div id="content-padd">
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
            </div>
        </div>
        <div class="clear"></div>
    </div>

    <div id="footer" class="noprint">
        <div id="footer-padd">
            <p style="float:left;margin-left:10px;">Powered by <a href="http://www.freenetis.org/">FreenetIS</a></p>
            <div class="clear"></div>
        </div>
    </div>
</div>

@auth
<script>
(function(){
    var timer=null,input=document.getElementById('search-input'),whisper=document.getElementById('whisper');
    if(!input||!whisper)return;
    input.addEventListener('input',function(){
        clearTimeout(timer);var q=this.value.trim();
        if(q.length<3){whisper.style.display='none';return;}
        timer=setTimeout(function(){fetchResults(q);},400);
    });
    input.addEventListener('keydown',function(e){if(e.key==='Escape')whisper.style.display='none';});
    document.addEventListener('click',function(e){
        var w=document.getElementById('search-wrapper');
        if(w&&!w.contains(e.target))whisper.style.display='none';
    });
    function fetchResults(q){
        fetch('{{ route("search.ajax") }}?q='+encodeURIComponent(q))
            .then(function(r){return r.json();})
            .then(function(data){
                if(!data.length){whisper.style.display='none';return;}
                var html=data.map(function(item){
                    return '<a href="'+item.url+'" style="display:block;padding:6px 10px;text-decoration:none;color:#333;border-bottom:1px solid #eee;">'+
                        '<strong style="color:#c00;">'+highlight(item.title,q)+'</strong>'+
                        (item.detail?'<br><small style="color:#666;">'+highlight(item.detail,q)+'</small>':'')+
                        '</a>';
                }).join('');
                html+='<a href="{{ route("search") }}?q='+encodeURIComponent(q)+'" style="display:block;padding:5px 10px;background:#f5f5f5;color:#666;text-decoration:none;font-size:.85em;text-align:center;">Zobrazit všechny výsledky →</a>';
                whisper.innerHTML=html;whisper.style.display='block';
            })
            .catch(function(){whisper.style.display='none';});
    }
    function highlight(text,q){
        if(!q||!text)return text;
        var e=q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&');
        return String(text).replace(new RegExp('('+e+')','gi'),'<span style="background:#ffff00;font-weight:bold;">$1</span>');
    }
})();
</script>
@endauth
</body>
</html>
@endif
