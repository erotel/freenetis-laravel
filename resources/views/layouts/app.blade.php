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
    <link rel="stylesheet" type="text/css" href="{{ asset('media/css/jquery.jstree.css') }}">
    <link rel="stylesheet" type="text/css" href="{{ asset('media/css/jquery.validate.password.css') }}">
    <link rel="stylesheet" type="text/css" media="handheld" href="{{ asset('media/css/m.style.css') }}">
    <link rel="stylesheet" type="text/css" media="print" href="{{ asset('media/css/print.css') }}">

    <script type="text/javascript" src="{{ asset('media/js/jquery.min.js') }}"></script>
    <script type="text/javascript" src="{{ asset('media/js/jquery-ui.min.js') }}"></script>
    <script type="text/javascript" src="{{ asset('media/js/jquery.ui.datepicker-cs.js') }}"></script>
    <script type="text/javascript" src="{{ asset('media/js/jquery.validate.min.js') }}"></script>
    <script type="text/javascript" src="{{ asset('media/js/jquery.cookie.min.js') }}"></script>
    <script type="text/javascript" src="{{ asset('media/js/jquery.validate.password.js') }}"></script>
    <script type="text/javascript" src="{{ asset('media/js/jquery.metadata.js') }}"></script>
    <script type="text/javascript" src="{{ asset('media/js/jquery.tablesorter.js') }}"></script>
    <script type="text/javascript" src="{{ asset('media/js/jquery.form.min.js') }}"></script>
    <script type="text/javascript" src="{{ asset('media/js/jquery.timer.js') }}"></script>
    <script type="text/javascript" src="{{ asset('media/js/jquery.autoresize.js') }}"></script>
    <script type="text/javascript" src="{{ asset('media/js/jquery.jstree.js') }}"></script>
    <script type="text/javascript" src="{{ asset('media/js/messages_cs.js') }}"></script>
    <script type="text/javascript" src="{{ asset('media/js/php.min.js') }}"></script>
</head>
<body>
<div id="main">
    <div id="header">
        <div id="logo">
            <a href="{{ url('/') }}">FreenetIS</a>
        </div>
        <div id="user-info">
            <span>{{ auth()->user()->name }} {{ auth()->user()->surname }}</span>
            (<span>{{ auth()->user()->login }}</span>)
            <form method="POST" action="{{ route('logout') }}" style="display:inline;">
                @csrf
                <button type="submit">Odhlásit se</button>
            </form>
        </div>
    </div>{{-- #header --}}

    <div id="middle">
        <div id="menu">
            @yield('menu')
        </div>{{-- #menu --}}

        <div id="content">
            @if(session('success'))
                <div class="message success">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="message error">{{ session('error') }}</div>
            @endif

            @yield('breadcrumbs')

            @yield('content')
        </div>{{-- #content --}}
    </div>{{-- #middle --}}

    <div id="footer">
        Powered by FreenetIS
    </div>{{-- #footer --}}
</div>{{-- #main --}}
</body>
</html>
@endif
