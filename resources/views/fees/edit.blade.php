@extends('layouts.app')

@section('title', 'Upravit tarif')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('fees.index') }}">Tarify</a> &raquo;
        Upravit tarif
    </div>
@endsection

@section('content')
    <h2>Upravit tarif</h2>

    <form method="POST" action="{{ route('fees.update', $fee->id) }}" class="form">
        @csrf
        @method('PUT')
        @include('fees._form', ['fee' => $fee])
        <p><input type="submit" value="Uložit"></p>
    </form>
@endsection
