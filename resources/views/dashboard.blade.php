@extends('layouts.app')

@section('title', 'Dashboard')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('content')
    <h2>Vítejte, {{ auth()->user()->name }} {{ auth()->user()->surname }}</h2>
    <p>Přihlašovací jméno: <strong>{{ auth()->user()->login }}</strong></p>
    <p>Typ uživatele: {{ auth()->user()->type == 1 ? 'Hlavní uživatel' : 'Uživatel' }}</p>
    <p>Member ID: {{ auth()->user()->member_id }}</p>

    @can('freenetis', ['view', 'Users_Controller', 'users'])
        <p style="margin-top:1rem; color: green;">Máte právo prohlížet uživatele (view_all)</p>
    @endcan
@endsection
