@extends('layouts.app')
@section('title', 'Přidat třídu rychlosti')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('speed_classes.index') }}">Třídy rychlosti</a> &raquo; Přidat třídu rychlosti
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Přidat novou třídu rychlosti</h2></div>

<form method="POST" action="{{ route('speed_classes.store') }}">
@csrf
@include('speed_classes._form')
<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Přidat</button>
    <a class="m-btn" href="{{ route('speed_classes.index') }}">Zrušit</a>
</div>
</form>
</div>
@endsection
