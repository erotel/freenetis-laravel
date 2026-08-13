@extends('layouts.app')
@section('title', 'Dvoufázové přihlášení')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">Dvoufázové přihlášení</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Dvoufázové přihlášení (MFA)</h2></div>

@if(session('success'))<div class="m-alert m-alert-success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="m-alert m-alert-danger">{{ $errors->first() }}</div>@endif

<div class="m-card" style="margin-bottom:16px">
    <p style="font-size:15px;color:#444;margin-bottom:12px">
        Druhý faktor chrání účet, i kdyby někdo znal vaše heslo — při přihlášení
        se navíc ověří 6místným kódem z aplikace v telefonu.
    </p>

    @if($enabled)
        <div style="display:inline-block;padding:2px 10px;border-radius:12px;font-weight:600;color:#1a7f37;background:#e6f4ea;margin-bottom:12px">
            ✔ Zapnuto
        </div>
        <p style="font-size:14px;color:#666;margin-bottom:16px">
            Zbývá <strong>{{ $recoveryLeft }}</strong> záložních kódů.
            @if($recoveryLeft <= 2)<span style="color:#b02a37">Doporučujeme přegenerovat.</span>@endif
        </p>

        <div style="display:flex;flex-wrap:wrap;gap:16px">
            <form method="POST" action="{{ route('mfa.recovery.regenerate') }}" style="flex:1 1 260px;border:1px solid #eee;border-radius:6px;padding:12px">
                @csrf
                <div style="font-weight:600;margin-bottom:6px">Přegenerovat záložní kódy</div>
                <div style="font-size:13px;color:#888;margin-bottom:8px">Staré kódy přestanou platit.</div>
                <input type="password" name="password" placeholder="Vaše heslo" required
                       style="width:100%;padding:6px 8px;border:1px solid #ccc;border-radius:4px;margin-bottom:8px">
                <button class="m-btn">Přegenerovat</button>
            </form>

            <form method="POST" action="{{ route('mfa.disable') }}" style="flex:1 1 260px;border:1px solid #f5c6cb;border-radius:6px;padding:12px"
                  onsubmit="return confirm('Opravdu vypnout dvoufázové přihlášení?')">
                @csrf
                <div style="font-weight:600;margin-bottom:6px;color:#b02a37">Vypnout MFA</div>
                <div style="font-size:13px;color:#888;margin-bottom:8px">Účet bude chráněný jen heslem.</div>
                <input type="password" name="password" placeholder="Vaše heslo" required
                       style="width:100%;padding:6px 8px;border:1px solid #ccc;border-radius:4px;margin-bottom:8px">
                <button class="m-btn m-btn-danger">Vypnout</button>
            </form>
        </div>
    @else
        <div style="display:inline-block;padding:2px 10px;border-radius:12px;font-weight:600;color:#9a6700;background:#fff8e1;margin-bottom:12px">
            Vypnuto
        </div>
        <div>
            <a class="m-btn m-btn-success" href="{{ route('mfa.setup') }}">Zapnout dvoufázové přihlášení</a>
        </div>
    @endif
</div>

</div>
@endsection
