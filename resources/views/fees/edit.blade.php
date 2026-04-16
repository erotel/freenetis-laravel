@extends('layouts.app')
@section('title', 'Upravit tarif')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('fees.index') }}">Tarify</a> &raquo; Upravit tarif
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Upravit tarif</h2></div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

<form method="POST" action="{{ route('fees.update', $fee->id) }}">
@csrf
@method('PUT')
@include('fees._form', ['fee' => $fee])
<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Uložit</button>
    <a class="m-btn" href="{{ route('fees.index') }}">Zrušit</a>
</div>
</form>
</div>
@endsection
