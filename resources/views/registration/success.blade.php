@extends('layouts.app')
@section('title', 'Registrace odeslána')
@section('menu') @endsection
@section('breadcrumbs') @endsection
@section('content')
<div style="max-width:600px; margin:3em auto; text-align:center;">
    <div style="background:#d4edda; border:1px solid #c3e6cb; padding:2em; border-radius:8px;">
        <h2 style="color:#155724;">✓ Registrace byla odeslána</h2>
        <p style="margin-top:1em;">Vaše registrace byla úspěšně přijata.</p>
        <p>Správce sítě vás bude kontaktovat ohledně dalšího postupu a podpisu smlouvy.</p>
        <p style="margin-top:1.5em;">
            <a href="{{ route('login') }}">← Přejít na přihlášení</a>
        </p>
    </div>
</div>
@endsection
