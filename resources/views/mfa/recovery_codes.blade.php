@extends('layouts.app')
@section('title', 'Záložní kódy')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs"><a href="{{ route('mfa.status') }}" class="m-link">Dvoufázové přihlášení</a> / Záložní kódy</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Záložní kódy</h2></div>

@if($context === 'enabled')
    <div class="m-alert m-alert-success">Dvoufázové přihlášení je zapnuté. ✔</div>
@endif

<div class="m-card" style="margin-bottom:16px;max-width:520px">
    <div style="background:#fff8e1;border:1px solid #f5c96b;border-radius:6px;padding:12px;margin-bottom:16px;font-size:14px;color:#7a5b00">
        <strong>Uložte si tyto kódy na bezpečné místo</strong> (vytiskněte / uložte do správce hesel).
        Ukazují se <strong>jen teď</strong>. Každý kód lze použít <strong>jednou</strong> místo kódu z aplikace,
        když nemáte telefon po ruce.
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 24px;font-family:monospace;font-size:17px;letter-spacing:1px;margin-bottom:16px">
        @foreach($codes as $c)
            <div style="padding:4px 0;border-bottom:1px dashed #eee">{{ $c }}</div>
        @endforeach
    </div>

    <a class="m-btn m-btn-success" href="{{ route('mfa.status') }}">Uložil/a jsem si je – hotovo</a>
</div>

</div>
@endsection
