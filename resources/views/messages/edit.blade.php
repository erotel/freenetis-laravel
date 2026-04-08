@extends('layouts.app')
@section('title', 'Upravit zprávu')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('messages.index') }}">Zprávy</a> &raquo;
        <a href="{{ route('messages.show', $message->id) }}">{{ $message->name }}</a> &raquo; Upravit
    </div>
@endsection
@section('content')
    <h2>Upravit zprávu: {{ $message->name }}</h2>
    @include('messages._form', ['message' => $message, 'action' => route('messages.update', $message->id), 'method' => 'PUT'])
@endsection
