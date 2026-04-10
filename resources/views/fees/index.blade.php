@extends('layouts.app')

@section('title', 'Tarify')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('fees.index') }}">Tarify</a>
    </div>
@endsection

@section('content')
    <h2>Tarify</h2>

    @if($canNew)
        <p>
            <a href="{{ route('fees.create') }}">
                <img src="{{ asset('media/images/icons/ico_add.gif') }}" alt="Přidat">
                Přidat tarif
            </a>
        </p>
    @endif

    @php
        $nextDir = fn(string $col) => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
        $arrow   = fn(string $col) => $sort === $col ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
        $sortUrl = fn(string $col) => request()->fullUrlWithQuery(['sort' => $col, 'dir' => $nextDir($col), 'page' => 1]);
    @endphp

    <div class="pagination-wrap">{{ $fees->links() }}</div>
    <table class="extended" cellspacing="0">
        <thead>
            <tr>
                <th><a href="{{ $sortUrl('id') }}">ID{{ $arrow('id') }}</a></th>
                <th><a href="{{ $sortUrl('type_id') }}">Typ{{ $arrow('type_id') }}</a></th>
                <th><a href="{{ $sortUrl('name') }}">Název{{ $arrow('name') }}</a></th>
                <th style="text-align:right;"><a href="{{ $sortUrl('fee') }}">Poplatek{{ $arrow('fee') }}</a></th>
                <th><a href="{{ $sortUrl('from') }}">Datum od{{ $arrow('from') }}</a></th>
                <th><a href="{{ $sortUrl('to') }}">Datum do{{ $arrow('to') }}</a></th>
                <th>Akce</th>
            </tr>
        </thead>
        <tbody>
            @forelse($fees as $fee)
                <tr>
                    <td>{{ $fee->id }}</td>
                    <td>{{ \App\Models\Fee::typeLabels()[$fee->type_id] ?? $fee->enumType?->value ?? $fee->type_id }}</td>
                    <td>{{ $fee->name ?: '—' }}</td>
                    <td style="text-align:right;">{{ number_format($fee->fee, 2, ',', ' ') }} Kč</td>
                    <td>{{ $fee->from?->format('d.m.Y') }}</td>
                    <td>{{ $fee->to && $fee->to->year < 9999 ? $fee->to->format('d.m.Y') : '∞' }}</td>
                    <td class="action">
                        @if(!$fee->readonly && $canEdit)
                            <a href="{{ route('fees.edit', $fee->id) }}" title="Upravit">
                                <img src="{{ asset('media/images/icons/gtk_edit.png') }}" alt="Upravit">
                            </a>
                        @endif
                        @if(!$fee->readonly && $canDelete)
                            <form method="POST" action="{{ route('fees.destroy', $fee->id) }}" style="display:inline;"
                                  onsubmit="return confirm('Opravdu smazat tarif?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="icon-button" title="Smazat">
                                    <img src="{{ asset('media/images/icons/delete.png') }}" alt="Smazat">
                                </button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7">Žádné tarify.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="pagination-wrap">{{ $fees->links() }}</div>
@endsection
