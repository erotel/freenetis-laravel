@extends('layouts.app')

@section('title', 'Bankovní účty')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('bank_accounts.index') }}">Bankovní účty</a>
    </div>
@endsection

@section('content')
    <h2>Bankovní účty</h2>

    <table class="extended" cellspacing="0">
        <thead>
            <tr>
                <th>ID</th>
                <th>Název</th>
                <th>Číslo účtu</th>
                <th>IBAN</th>
                <th>Člen</th>
                <th>Akce</th>
            </tr>
        </thead>
        <tbody>
            @forelse($accounts as $account)
                <tr>
                    <td>{{ $account->id }}</td>
                    <td>{{ $account->name }}</td>
                    <td>{{ $account->full_account_number }}</td>
                    <td>{{ $account->IBAN ?: '—' }}</td>
                    <td>
                        @if($account->member)
                            <a href="{{ route('members.show', $account->member_id) }}">{{ $account->member->name }}</a>
                        @else
                            —
                        @endif
                    </td>
                    <td class="action">
                        <a href="{{ route('bank_accounts.show', $account->id) }}" title="Detail">
                            <img src="{{ asset('media/images/icons/con_info.png') }}" alt="Detail">
                        </a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6">Žádné bankovní účty.</td></tr>
            @endforelse
        </tbody>
    </table>
@endsection
