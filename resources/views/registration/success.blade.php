@extends('layouts.app')
@section('title', 'Registrace odeslána')
@section('menu') @endsection
@section('breadcrumbs') @endsection
@section('content')
<div class="m-page" style="max-width:560px;margin:3em auto">
    <div class="m-alert m-alert-success" style="text-align:center;padding:2rem">
        <div style="font-size:38px;margin-bottom:12px">✓</div>
        <h2 style="margin:0 0 8px;font-size:24px">Registrace byla odeslána</h2>
        <p style="margin:0 0 8px">Vaše registrace byla úspěšně přijata.</p>
        <p style="margin:0 0 16px">Správce sítě vás bude kontaktovat ohledně dalšího postupu a podpisu smlouvy.</p>
        <a class="m-btn" href="{{ route('login') }}">&larr; Přejít na přihlášení</a>
    </div>
</div>
@endsection
