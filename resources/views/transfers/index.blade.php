@extends('layouts.app')

@section('title', 'Deník převodů')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('transfers.index') }}">Převody</a>
    </div>
@endsection

@section('content')
    <h2>Deník převodů</h2>

    @if(session('success'))
        <p class="success">{{ session('success') }}</p>
    @endif

    {{-- Active filters --}}
    @if(request('account_id') || request('member_id'))
        <p>
            Filtr:
            @if(request('account_id'))
                účet #{{ request('account_id') }}
                <a href="{{ request()->fullUrlWithQuery(['account_id' => null, 'page' => 1]) }}">[×]</a>
            @endif
            @if(request('member_id'))
                člen #{{ request('member_id') }}
                <a href="{{ request()->fullUrlWithQuery(['member_id' => null, 'page' => 1]) }}">[×]</a>
            @endif
        </p>
    @endif

    @php
        $nextDir = fn(string $col) => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
        $arrow   = fn(string $col) => $sort === $col ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
        $sortUrl = fn(string $col) => request()->fullUrlWithQuery(['sort' => $col, 'dir' => $nextDir($col), 'page' => 1]);
    @endphp

    <table class="extended" cellspacing="0">
        <thead>
            <tr>
                <th><a href="{{ $sortUrl('id') }}">ID{{ $arrow('id') }}</a></th>
                <th><a href="{{ $sortUrl('datetime') }}">Datum{{ $arrow('datetime') }}</a></th>
                <th>Popis</th>
                <th>Z účtu</th>
                <th>Na účet</th>
                <th>Člen</th>
                <th><a href="{{ $sortUrl('amount') }}">Částka{{ $arrow('amount') }}</a></th>
                <th>Typ</th>
                <th>Akce</th>
            </tr>
        </thead>
        <tbody>
            @forelse($transfers as $transfer)
                <tr>
                    <td>{{ $transfer->id }}</td>
                    <td>{{ $transfer->datetime?->format('d.m.Y') }}</td>
                    <td>{{ $transfer->text }}</td>
                    <td>
                        @if($transfer->origin)
                            <a href="{{ route('accounts.show', $transfer->origin_id) }}">{{ $transfer->origin->name }}</a>
                        @else
                            —
                        @endif
                    </td>
                    <td>
                        @if($transfer->destination)
                            <a href="{{ route('accounts.show', $transfer->destination_id) }}">{{ $transfer->destination->name }}</a>
                        @else
                            —
                        @endif
                    </td>
                    <td>
                        @if($transfer->member)
                            <a href="{{ route('members.show', $transfer->member_id) }}">{{ $transfer->member->name }}</a>
                        @else
                            —
                        @endif
                    </td>
                    <td style="text-align:right;">
                        {{ number_format($transfer->amount, 2, ',', ' ') }} Kč
                    </td>
                    <td>{{ \App\Models\Transfer::typeLabel($transfer->type ?? 0) }}</td>
                    <td>
                        <a href="{{ route('transfers.show', $transfer->id) }}" title="Detail">
                            <img src="{{ asset('media/images/icons/con_info.png') }}" alt="Detail">
                        </a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9">Žádné převody nebyly nalezeny.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="pagination-wrap">
        {{ $transfers->links() }}
    </div>

    <form method="GET" action="{{ route('transfers.index') }}" style="margin-top:1em;">
        @if(request('sort'))     <input type="hidden" name="sort"       value="{{ $sort }}"> @endif
        @if(request('dir'))      <input type="hidden" name="dir"        value="{{ $dir }}">  @endif
        @if(request('account_id')) <input type="hidden" name="account_id" value="{{ request('account_id') }}"> @endif
        @if(request('member_id'))  <input type="hidden" name="member_id"  value="{{ request('member_id') }}">  @endif
        Záznamů na stránku:
        <select name="record_per_page" onchange="this.form.submit()">
            @foreach([50, 100, 150, 200, 250, 300] as $n)
                <option value="{{ $n }}" @selected($perPage === $n)>{{ $n }}</option>
            @endforeach
        </select>
    </form>
@endsection
