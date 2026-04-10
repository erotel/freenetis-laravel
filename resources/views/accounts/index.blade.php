@extends('layouts.app')

@section('title', 'Účty')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('accounts.index') }}">Účty</a>
    </div>
@endsection

@section('content')
    <h2>Seznam účtů</h2>

    {{-- Filter tabs --}}
    @php
        $tabs = [
            'all'     => 'Všechny',
            'credit'  => 'Členské',
            'project' => 'Projektové',
            'other'   => 'Ostatní',
        ];
    @endphp
    <div style="margin-bottom:1em;">
        @foreach($tabs as $key => $label)
            @if($type === $key)
                <strong>{{ $label }}</strong>
            @else
                <a href="{{ request()->fullUrlWithQuery(['type' => $key, 'page' => 1]) }}">{{ $label }}</a>
            @endif
            @if(!$loop->last) | @endif
        @endforeach
    </div>

    @if($canNew)
        <p>
            <a href="{{ route('accounts.create') }}">
                <img src="{{ asset('media/images/icons/ico_add.gif') }}" alt="Přidat">
                Přidat projektový účet
            </a>
        </p>
    @endif

    @php
        $nextDir = fn(string $col) => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
        $arrow   = fn(string $col) => $sort === $col ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
        $sortUrl = fn(string $col) => request()->fullUrlWithQuery(['sort' => $col, 'dir' => $nextDir($col), 'page' => 1]);
    @endphp

    {{ $accounts->links() }}
    <table class="extended" cellspacing="0">
        <thead>
            <tr>
                <th><a href="{{ $sortUrl('id') }}">ID{{ $arrow('id') }}</a></th>
                <th><a href="{{ $sortUrl('name') }}">Název{{ $arrow('name') }}</a></th>
                <th>Typ účtu</th>
                <th>Člen</th>
                <th><a href="{{ $sortUrl('balance') }}">Zůstatek{{ $arrow('balance') }}</a></th>
                <th>Akce</th>
            </tr>
        </thead>
        <tbody>
            @forelse($accounts as $account)
                @php
                    $balanceColor = $account->balance > 0 ? 'green' : ($account->balance < 0 ? 'red' : 'inherit');
                @endphp
                <tr>
                    <td>{{ $account->id }}</td>
                    <td><a href="{{ route('accounts.show', $account->id) }}">{{ $account->name }}</a></td>
                    <td>{{ $account->accountAttribute?->name ?? '—' }}</td>
                    <td>
                        @if($account->member)
                            <a href="{{ route('members.show', $account->member_id) }}">{{ $account->member->name }}</a>
                        @else
                            —
                        @endif
                    </td>
                    <td style="color:{{ $balanceColor }}; text-align:right;">
                        {{ number_format($account->balance, 2, ',', ' ') }} Kč
                    </td>
                    <td>
                        <a href="{{ route('accounts.show', $account->id) }}" title="Detail">
                            <img src="{{ asset('media/images/icons/con_info.png') }}" alt="Detail">
                        </a>
                        @if($canEdit)
                            <a href="{{ route('accounts.edit', $account->id) }}" title="Upravit">
                                <img src="{{ asset('media/images/icons/gtk_edit.png') }}" alt="Upravit">
                            </a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">Žádné účty nebyly nalezeny.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="pagination-wrap">
        {{ $accounts->links() }}
    </div>

    <form method="GET" action="{{ route('accounts.index') }}" style="margin-top:1em;">
        @if(request('sort'))  <input type="hidden" name="sort"  value="{{ $sort }}"> @endif
        @if(request('dir'))   <input type="hidden" name="dir"   value="{{ $dir }}">  @endif
        @if($type !== 'all')  <input type="hidden" name="type"  value="{{ $type }}"> @endif
        Záznamů na stránku:
        <select name="record_per_page" onchange="this.form.submit()">
            @foreach([50, 100, 150, 200, 250, 300, 350, 400, 450, 500] as $n)
                <option value="{{ $n }}" @selected($perPage === $n)>{{ $n }}</option>
            @endforeach
        </select>
    </form>
@endsection
