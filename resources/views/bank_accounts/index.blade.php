@extends('layouts.app')
@section('title', 'Bankovní účty')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs"><a href="{{ route('bank_accounts.index') }}">Bankovní účty</a></div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Bankovní účty spolku</h2></div>

<div class="m-card" style="padding:0;overflow-x:auto;margin-bottom:24px">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px">ID</th>
            <th>Název</th>
            <th>Číslo účtu</th>
            <th>IBAN</th>
            @if($canManageAutoDown)<th style="width:160px">Auto. stahování</th>@endif
            <th style="width:70px">Akce</th>
        </tr>
    </thead>
    <tbody>
        @forelse($associationAccounts as $account)
        <tr>
            <td>{{ $account->id }}</td>
            <td>{{ $account->name }}</td>
            <td style="font-family:monospace;font-size:14px">{{ $account->full_account_number }}</td>
            <td style="font-family:monospace;font-size:14px">{{ $account->IBAN ?: '—' }}</td>
            @if($canManageAutoDown)
            <td>
                <a class="m-link-sm" href="{{ route('bank_accounts.auto_downloads', $account->id) }}">
                    @if($autoDownFlags[$account->id] ?? false)
                        <span class="m-tag m-tag-green">Nastaveno</span>
                    @else
                        Nastavit
                    @endif
                </a>
            </td>
            @endif
            <td><a class="m-link-sm" href="{{ route('bank_accounts.show', $account->id) }}">Detail</a></td>
        </tr>
        @empty
        <tr><td colspan="{{ $canManageAutoDown ? 6 : 5 }}" style="text-align:center;color:#aaa;padding:2rem">Žádné účty spolku.</td></tr>
        @endforelse
    </tbody>
</table>
</div>

<div class="m-title-row"><h2>Bankovní účty členů</h2></div>

<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px">ID</th>
            <th>Název</th>
            <th>Číslo účtu</th>
            <th>IBAN</th>
            <th>Člen</th>
            <th style="width:70px">Akce</th>
        </tr>
    </thead>
    <tbody>
        @forelse($memberAccounts as $account)
        <tr>
            <td>{{ $account->id }}</td>
            <td>{{ $account->name }}</td>
            <td style="font-family:monospace;font-size:14px">{{ $account->full_account_number }}</td>
            <td style="font-family:monospace;font-size:14px">{{ $account->IBAN ?: '—' }}</td>
            <td>
                @if($account->member) <a class="m-link" href="{{ route('members.show', $account->member_id) }}">{{ $account->member->name }}</a>
                @else — @endif
            </td>
            <td><a class="m-link-sm" href="{{ route('bank_accounts.show', $account->id) }}">Detail</a></td>
        </tr>
        @empty
        <tr><td colspan="6" style="text-align:center;color:#aaa;padding:2rem">Žádné účty členů.</td></tr>
        @endforelse
    </tbody>
</table>
</div>
</div>
@endsection
