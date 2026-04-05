@extends('layouts.app')

@section('title', 'Přidat tarif')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('fees.index') }}">Tarify</a> &raquo;
        Přidat tarif
    </div>
@endsection

@section('content')
    <h2>Přidat tarif</h2>

    <form method="POST" action="{{ route('fees.store') }}" class="form">
        @csrf
        @include('fees._form', ['fee' => null])
        <p><input type="submit" value="Přidat"></p>
    </form>
@endsection
