@extends('layouts.app')
@section('title', 'Upravit třídu rychlosti')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('speed_classes.index') }}">Třídy rychlosti</a> &raquo; Upravit: {{ $speedClass->name }}
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Upravit třídu rychlosti: {{ $speedClass->name }}</h2></div>

<form method="POST" action="{{ route('speed_classes.update', $speedClass->id) }}">
@csrf
@method('PUT')
@include('speed_classes._form', [
    'name'     => $speedClass->name,
    'qos_ceil' => \App\Models\SpeedClass::toBpsSlash($speedClass->d_ceil, $speedClass->u_ceil),
    'qos_rate' => \App\Models\SpeedClass::toBpsSlash($speedClass->d_rate, $speedClass->u_rate),
])
<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Uložit</button>
    <a class="m-btn" href="{{ route('speed_classes.index') }}">Zrušit</a>
</div>
</form>
</div>
@endsection
