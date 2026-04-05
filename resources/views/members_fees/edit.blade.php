@extends('layouts.app')

@section('title', 'Upravit tarif členu: ' . $memberFee->member->name)

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('members.index') }}">Členové</a> &raquo;
        <a href="{{ route('members.show', $memberFee->member_id) }}">{{ $memberFee->member->name }}</a> &raquo;
        <a href="{{ route('members_fees.by_member', $memberFee->member_id) }}">Tarify</a> &raquo;
        Upravit tarif
    </div>
@endsection

@section('content')
    <h2>Upravit tarif členu: {{ $memberFee->member->name }}</h2>

    <form method="POST" action="{{ route('members_fees.update', $memberFee->id) }}" class="form">
        @csrf
        @method('PUT')
        @include('members_fees._form', ['memberFee' => $memberFee])
        <p><input type="submit" value="Uložit"></p>
    </form>
@endsection
