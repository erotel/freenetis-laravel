@extends('layouts.app')
@section('title', 'Nová zpráva')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
    <div id="breadcrumbs"><a href="{{ route('messages.index') }}">Zprávy</a> &raquo; Nová zpráva</div>
@endsection
@section('content')
    <h2>Nová zpráva</h2>
    @include('messages._form', ['message' => null, 'action' => route('messages.store'), 'method' => 'POST'])
@endsection
