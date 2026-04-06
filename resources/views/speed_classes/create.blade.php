@extends('layouts.app')

@section('title', 'Přidat třídu rychlosti')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('speed_classes.index') }}">Třídy rychlosti</a> &raquo;
        Přidat třídu rychlosti
    </div>
@endsection

@section('content')
    <h2>Přidat novou třídu rychlosti</h2>

    <form method="POST" action="{{ route('speed_classes.store') }}">
        @csrf
        @include('speed_classes._form')
        <p>
            <button type="submit" class="btn">Přidat</button>
            <a href="{{ route('speed_classes.index') }}">Zpět</a>
        </p>
    </form>
@endsection
