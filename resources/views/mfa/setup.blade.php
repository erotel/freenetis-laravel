@extends('layouts.app')
@section('title', 'Zapnout MFA')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs"><a href="{{ route('mfa.status') }}" class="m-link">Dvoufázové přihlášení</a> / Zapnutí</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Zapnout dvoufázové přihlášení</h2></div>

@if($errors->any())<div class="m-alert m-alert-danger">{{ $errors->first() }}</div>@endif

<div class="m-card" style="margin-bottom:16px">
    <ol style="font-size:15px;color:#444;line-height:1.7;margin:0 0 16px 20px">
        <li>Nainstalujte si do telefonu autentizační aplikaci (Google Authenticator, Aegis, 1Password…).</li>
        <li>Naskenujte tento QR kód (nebo zadejte klíč ručně).</li>
        <li>Opište 6místný kód, který se v aplikaci objeví, a potvrďte.</li>
    </ol>

    <div style="display:flex;flex-wrap:wrap;gap:24px;align-items:flex-start">
        <div style="flex:0 0 auto;border:1px solid #eee;border-radius:8px;padding:8px;background:#fff">
            {!! $qrSvg !!}
        </div>

        <div style="flex:1 1 260px">
            <div style="font-size:13px;color:#888;margin-bottom:4px">Klíč pro ruční zadání:</div>
            <div style="font-family:monospace;font-size:16px;letter-spacing:2px;background:#f6f8fa;border:1px solid #e1e4e8;border-radius:4px;padding:8px 10px;word-break:break-all;margin-bottom:16px">
                {{ $secret }}
            </div>

            <form method="POST" action="{{ route('mfa.confirm') }}">
                @csrf
                <label style="display:block;font-size:14px;color:#444;margin-bottom:4px">Kód z aplikace</label>
                <input type="text" name="code" required autofocus autocomplete="one-time-code" placeholder="000000"
                       style="width:160px;padding:8px 10px;border:1px solid #ccc;border-radius:4px;font-size:1.3rem;letter-spacing:.25em;text-align:center;margin-bottom:12px">
                <div>
                    <button class="m-btn m-btn-success">Potvrdit a zapnout</button>
                    <a class="m-btn" href="{{ route('mfa.status') }}">Zrušit</a>
                </div>
            </form>
        </div>
    </div>
</div>

</div>
@endsection
