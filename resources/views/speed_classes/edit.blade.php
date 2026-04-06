@extends('layouts.app')

@section('title', 'Upravit třídu rychlosti')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('speed_classes.index') }}">Třídy rychlosti</a> &raquo;
        Upravit: {{ $speedClass->name }}
    </div>
@endsection

@section('content')
    <h2>Upravit třídu rychlosti: {{ $speedClass->name }}</h2>

    <form method="POST" action="{{ route('speed_classes.update', $speedClass->id) }}">
        @csrf
        @method('PUT')
        @include('speed_classes._form', [
            'name'     => $speedClass->name,
            'qos_ceil' => \App\Models\SpeedClass::toBpsSlash($speedClass->d_ceil, $speedClass->u_ceil),
            'qos_rate' => \App\Models\SpeedClass::toBpsSlash($speedClass->d_rate, $speedClass->u_rate),
        ])
        <p>
            <button type="submit" class="btn">Uložit</button>
            <a href="{{ route('speed_classes.index') }}">Zpět</a>
        </p>
    </form>
@endsection
