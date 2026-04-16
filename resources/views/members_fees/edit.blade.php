@extends('layouts.app')
@section('title', 'Upravit tarif členu: ' . $memberFee->member->name)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('members.index') }}">Členové</a> &raquo;
    <a href="{{ route('members.show', $memberFee->member_id) }}">{{ $memberFee->member->name }}</a> &raquo;
    <a href="{{ route('members_fees.by_member', $memberFee->member_id) }}">Tarify</a> &raquo;
    Upravit tarif
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Upravit tarif členu: {{ $memberFee->member->name }}</h2></div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

<form method="POST" action="{{ route('members_fees.update', $memberFee->id) }}">
@csrf
@method('PUT')
@include('members_fees._form', ['memberFee' => $memberFee])
<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Uložit</button>
    <a class="m-btn" href="{{ route('members_fees.by_member', $memberFee->member_id) }}">Zrušit</a>
</div>
</form>
</div>
@endsection
