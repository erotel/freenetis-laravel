@extends('layouts.app')
@section('title', 'Upravit pravidlo aktivace')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('messages.index') }}">Zprávy</a> »
        <a href="{{ route('messages.show', $message->id) }}">{{ $message->name }}</a> »
        Upravit pravidlo
    </div>
@endsection
@section('content')
    <h2>Upravit pravidlo: {{ $message->name }}</h2>
    @include('message_auto_settings._form', ['rule' => $rule, 'action' => route('message-auto-settings.update', [$message->id, $rule->id]), 'method' => 'PUT'])
@endsection
