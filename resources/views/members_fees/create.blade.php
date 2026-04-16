@extends('layouts.app')
@section('title', 'Přidat tarif členu: ' . $member->name)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('members.index') }}">Členové</a> &raquo;
    <a href="{{ route('members.show', $member->id) }}">{{ $member->name }}</a> &raquo;
    <a href="{{ route('members_fees.by_member', $member->id) }}">Tarify</a> &raquo;
    Přidat tarif
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Přidat tarif členu: {{ $member->name }}</h2></div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

<form method="POST" action="{{ route('members_fees.store', $member->id) }}">
@csrf
@include('members_fees._form', ['memberFee' => null])
<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Přidat</button>
    <a class="m-btn" href="{{ route('members_fees.by_member', $member->id) }}">Zrušit</a>
</div>
</form>
</div>
@endsection
