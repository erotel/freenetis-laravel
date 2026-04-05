@extends('layouts.app')

@section('title', 'Tarify člena: ' . $member->name)

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('members.index') }}">Členové</a> &raquo;
        <a href="{{ route('members.show', $member->id) }}">{{ $member->name }}</a> &raquo;
        Tarify
    </div>
@endsection

@section('content')
    <h2>Tarify člena: {{ $member->name }}</h2>

    <div style="margin-bottom:1em;">
        <a href="{{ route('members.show', $member->id) }}">&larr; Zpět na profil člena</a>
    </div>

    @foreach($typeLabels as $typeId => $typeLabel)
        @php
            $rows       = $memberFees->get($typeId, collect());
            $defaults   = $defaultFees->get($typeId, collect());
            $showDefault = $rows->isEmpty() && $defaults->isNotEmpty();
        @endphp

        <h3>{{ $typeLabel }}</h3>

        @if($canAdd)
            <p>
                <a href="{{ route('members_fees.create', ['memberId' => $member->id, 'type_id' => $typeId]) }}">
                    <img src="{{ asset('media/images/icons/ico_add.gif') }}" alt="Přidat">
                    Přidat tarif tohoto typu
                </a>
            </p>
        @endif

        <table class="extended" cellspacing="0">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Název</th>
                    <th style="text-align:right;">Poplatek</th>
                    <th>Aktivace</th>
                    <th>Deaktivace</th>
                    <th>Stav</th>
                    <th>Komentář</th>
                    <th>Akce</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $mf)
                    @php
                        $isActive = $mf->activation_date <= today() &&
                                    ($mf->deactivation_date === null || $mf->deactivation_date >= today());
                    @endphp
                    <tr>
                        <td>{{ $mf->id }}</td>
                        <td>{{ $mf->fee->name ?: '—' }}</td>
                        <td style="text-align:right;">{{ number_format($mf->fee->fee, 2, ',', ' ') }} Kč</td>
                        <td>{{ $mf->activation_date?->format('d.m.Y') }}</td>
                        <td>{{ $mf->deactivation_date && $mf->deactivation_date->year < 9999 ? $mf->deactivation_date->format('d.m.Y') : '∞' }}</td>
                        <td style="color:{{ $isActive ? 'green' : 'red' }}">{{ $isActive ? 'Aktivní' : 'Neaktivní' }}</td>
                        <td>{{ $mf->comment ?: '—' }}</td>
                        <td class="action">
                            @if($canEdit)
                                <a href="{{ route('members_fees.edit', $mf->id) }}" title="Upravit">
                                    <img src="{{ asset('media/images/icons/gtk_edit.png') }}" alt="Upravit">
                                </a>
                            @endif
                            @if($canDelete)
                                <form method="POST" action="{{ route('members_fees.destroy', $mf->id) }}"
                                      style="display:inline;"
                                      onsubmit="return confirm('Odebrat tarif?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="icon-button" title="Odebrat">
                                        <img src="{{ asset('media/images/icons/delete.png') }}" alt="Odebrat">
                                    </button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    @if($showDefault)
                        @foreach($defaults as $mf)
                            @php
                                $isActive = $mf->activation_date <= today() &&
                                            ($mf->deactivation_date === null || $mf->deactivation_date >= today());
                            @endphp
                            <tr style="color:#888;">
                                <td>{{ $mf->id }}</td>
                                <td>{{ $mf->fee->name ?: '—' }} <small>(výchozí)</small></td>
                                <td style="text-align:right;">{{ number_format($mf->fee->fee, 2, ',', ' ') }} Kč</td>
                                <td>{{ $mf->activation_date?->format('d.m.Y') }}</td>
                                <td>{{ $mf->deactivation_date && $mf->deactivation_date->year < 9999 ? $mf->deactivation_date->format('d.m.Y') : '∞' }}</td>
                                <td style="color:{{ $isActive ? 'green' : 'red' }}">{{ $isActive ? 'Aktivní' : 'Neaktivní' }}</td>
                                <td>{{ $mf->comment ?: '—' }}</td>
                                <td>—</td>
                            </tr>
                        @endforeach
                    @else
                        <tr><td colspan="8">Žádné tarify tohoto typu.</td></tr>
                    @endif
                @endforelse
            </tbody>
        </table>
    @endforeach
@endsection
