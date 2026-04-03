@extends('layouts.app')

@section('title', 'Účet: ' . $account->name)

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('accounts.index') }}">Účty</a> &raquo;
        {{ $account->name }}
    </div>
@endsection

@section('content')
    <h2>{{ $account->name }}</h2>

    @if(session('success'))
        <p class="success">{{ session('success') }}</p>
    @endif

    <div style="margin-bottom:1em;">
        @if($canEdit)
            <a href="{{ route('accounts.edit', $account->id) }}">
                <img src="{{ asset('media/images/icons/gtk_edit.png') }}" alt="Upravit">
                Upravit
            </a>
            &nbsp;
        @endif
        @if($canViewTransfers)
            <a href="{{ route('transfers.index', ['account_id' => $account->id]) }}">
                Zobrazit převody
            </a>
        @endif
    </div>

    <table class="extended" cellspacing="0">
        <thead>
            <tr><th colspan="2">Informace o účtu</th></tr>
        </thead>
        <tbody>
            <tr>
                <th>ID</th>
                <td>{{ $account->id }}</td>
            </tr>
            <tr>
                <th>Název</th>
                <td>{{ $account->name }}</td>
            </tr>
            <tr>
                <th>Typ účtu</th>
                <td>{{ $account->accountAttribute?->name ?? '—' }}</td>
            </tr>
            <tr>
                <th>Člen</th>
                <td>
                    @if($account->member)
                        <a href="{{ route('members.show', $account->member_id) }}">{{ $account->member->name }}</a>
                    @else
                        —
                    @endif
                </td>
            </tr>
            <tr>
                <th>Zůstatek</th>
                <td style="color:{{ $account->balance > 0 ? 'green' : ($account->balance < 0 ? 'red' : 'inherit') }}">
                    {{ number_format($account->balance, 2, ',', ' ') }} Kč
                </td>
            </tr>
            @if($account->comment)
                <tr>
                    <th>Komentář</th>
                    <td>{{ $account->comment }}</td>
                </tr>
            @endif
        </tbody>
    </table>
@endsection
