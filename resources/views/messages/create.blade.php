@extends('layouts.app')
@section('title', 'Nová zpráva')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs"><a href="{{ route('messages.index') }}">Zprávy</a> &raquo; Nová zpráva</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Nová zpráva</h2></div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

@include('messages._form', ['message' => null, 'action' => route('messages.store'), 'method' => 'POST'])
</div>
@endsection
