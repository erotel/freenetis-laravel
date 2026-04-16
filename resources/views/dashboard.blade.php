@extends('layouts.app')
@section('title', 'Dashboard')
@section('menu') <x-freenetis-menu /> @endsection
@section('content')
<div class="m-page">
<div class="m-title-row">
    <h2>Vítejte, {{ auth()->user()->name }} {{ auth()->user()->surname }}</h2>
</div>
<div class="m-subtitle">{{ auth()->user()->login }}</div>

<div class="m-card" style="max-width:360px">
    <div class="m-card-title">Informace o přihlášení</div>
    <div class="m-field">
        <span class="m-field-label">Přihlašovací jméno</span>
        <span class="m-field-value">{{ auth()->user()->login }}</span>
    </div>
    <div class="m-field">
        <span class="m-field-label">Typ uživatele</span>
        <span class="m-field-value">
            @if(auth()->user()->type == 1)
                <span class="m-tag m-tag-amber">Hlavní uživatel</span>
            @else
                <span class="m-tag m-tag-gray">Uživatel</span>
            @endif
        </span>
    </div>
    @if(auth()->user()->member_id)
    <div class="m-field">
        <span class="m-field-label">Member ID</span>
        <span class="m-field-value">{{ auth()->user()->member_id }}</span>
    </div>
    @endif
</div>

@can('freenetis', ['view', 'Users_Controller', 'users'])
<div class="m-alert m-alert-success" style="max-width:360px">Máte právo prohlížet uživatele.</div>
@endcan
</div>
@endsection
