@extends('layouts.app')

@section('title', 'Přidat tarif členu: ' . $member->name)

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('members.index') }}">Členové</a> &raquo;
        <a href="{{ route('members.show', $member->id) }}">{{ $member->name }}</a> &raquo;
        <a href="{{ route('members_fees.by_member', $member->id) }}">Tarify</a> &raquo;
        Přidat tarif
    </div>
@endsection

@section('content')
    <h2>Přidat tarif členu: {{ $member->name }}</h2>

    <form method="POST" action="{{ route('members_fees.store', $member->id) }}" class="form">
        @csrf
        @include('members_fees._form', ['memberFee' => null])
        <p><input type="submit" value="Přidat"></p>
    </form>
@endsection
