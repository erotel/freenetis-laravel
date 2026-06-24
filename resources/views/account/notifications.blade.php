@extends('layouts.app')
@section('title', 'Moje oznámení')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('dashboard') }}">Domů</a> &raquo;
    Moje oznámení
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Nastavení oznámení</h2></div>

@if(session('success'))
<div class="m-alert m-alert-success" style="margin-bottom:16px">{{ session('success') }}</div>
@endif

<div class="m-card" style="max-width:640px;padding:1.25rem">
    <p style="margin-top:0">
        Zde si můžeš vypnout jednotlivé kanály, kterými ti zasíláme upozornění
        a oznámení (např. blokace, výpadky, hromadné zprávy).
    </p>
    <p style="color:#888;font-size:14px">
        <strong>Pozor:</strong> přesměrování (redirect) slouží i ke kritickým provozním upozorněním —
        např. že na účtu chybí kredit. Vypnutí znamená, že tě na to v prohlížeči neupozorníme.
    </p>

    <form method="POST" action="{{ route('me.notifications.update') }}" style="margin-top:18px">
        @csrf
        <div style="display:flex;flex-direction:column;gap:14px">
            <label style="display:flex;gap:10px;align-items:flex-start;cursor:pointer">
                <input type="hidden" name="notification_by_redirection" value="0">
                <input type="checkbox" name="notification_by_redirection" value="1"
                       @checked($member->notification_by_redirection)
                       style="margin-top:3px">
                <span>
                    <strong>Přesměrování v prohlížeči (redirect)</strong>
                    <div style="color:#888;font-size:14px">
                        Když ti vyprší kredit nebo je nutné akce, zobrazí se ti místo webu naše hláška.
                    </div>
                </span>
            </label>

            <label style="display:flex;gap:10px;align-items:flex-start;cursor:pointer">
                <input type="hidden" name="notification_by_email" value="0">
                <input type="checkbox" name="notification_by_email" value="1"
                       @checked($member->notification_by_email)
                       style="margin-top:3px">
                <span>
                    <strong>E-mail</strong>
                    <div style="color:#888;font-size:14px">
                        Hromadná oznámení a upozornění na všechny tvé e-mailové kontakty.
                    </div>
                </span>
            </label>

            <label style="display:flex;gap:10px;align-items:flex-start;cursor:pointer">
                <input type="hidden" name="notification_by_sms" value="0">
                <input type="checkbox" name="notification_by_sms" value="1"
                       @checked($member->notification_by_sms)
                       style="margin-top:3px">
                <span>
                    <strong>SMS</strong>
                    <div style="color:#888;font-size:14px">
                        SMS na tvé telefonní kontakty (jen pokud je SMS gateway aktivní).
                    </div>
                </span>
            </label>
        </div>

        <div class="m-actions" style="margin-top:20px">
            <button class="m-btn m-btn-primary" type="submit">Uložit</button>
            <a class="m-btn" href="{{ route('members.show', $member->id) }}">Zpět</a>
        </div>
    </form>
</div>
</div>
@endsection
