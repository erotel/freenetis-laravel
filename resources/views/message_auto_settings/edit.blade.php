@extends('layouts.app')
@section('title', 'Upravit pravidlo aktivace')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('messages.index') }}">Zprávy</a> &raquo;
    <a href="{{ route('messages.show', $message->id) }}">{{ $message->name }}</a> &raquo;
    Upravit pravidlo
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Upravit pravidlo: {{ $message->name }}</h2></div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

@include('message_auto_settings._form', ['rule' => $rule, 'action' => route('message-auto-settings.update', [$message->id, $rule->id]), 'method' => 'PUT'])
</div>
@endsection
