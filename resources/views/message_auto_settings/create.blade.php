@extends('layouts.app')
@section('title', 'Přidat pravidlo aktivace')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('messages.index') }}">Zprávy</a> »
        <a href="{{ route('messages.show', $message->id) }}">{{ $message->name }}</a> »
        Přidat pravidlo
    </div>
@endsection
@section('content')
    <h2>Přidat pravidlo automatické aktivace</h2>
    <p>Zpráva: <strong>{{ $message->name }}</strong></p>
    @include('message_auto_settings._form', ['rule' => null, 'action' => route('message-auto-settings.store', $message->id), 'method' => 'POST'])
@endsection
