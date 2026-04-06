@extends('layouts.app')

@section('title', 'Povolené podsítě člena ' . $member->name)

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('members.index') }}">Členové</a> &raquo;
        <a href="{{ route('members.show', $member->id) }}">{{ $member->name }}</a> &raquo;
        Povolené podsítě
    </div>
@endsection

@section('content')
    <h2>Povolené podsítě člena {{ $member->name }}</h2>

    <p>
        <a href="{{ route('members.show', $member->id) }}">&larr; Zpět na profil člena</a>
    </p>

    @php
        $enabledCount = $allowedSubnets->where('enabled', true)->count();
        $maxCount = $member->allowed_subnets_count;
    @endphp

    <form method="POST" action="{{ route('allowed_subnets.update_count', $member->id) }}" style="display:inline;">
        @csrf
        @method('PUT')
        <strong>Max. povolených podsítí:</strong>
        <input type="number" name="allowed_subnets_count" min="0" style="width:50px;"
               value="{{ $member->allowed_subnets_count }}">
        <small>(0 = neomezeno)</small>
        <button type="submit">Uložit</button>
    </form>
    <br>
    <p>
        <strong>Zapnutých podsítí:</strong> {{ $enabledCount }}
        @if($maxCount > 0)
            / {{ $maxCount }} (maximum)
        @else
            (neomezeno)
        @endif
    </p>

    @if($canNew && $availableSubnets->count() > 0)
        <form method="POST" action="{{ route('allowed_subnets.store', $member->id) }}"
              style="margin-bottom:10px;">
            @csrf
            <select name="subnet_id" required>
                <option value="">— vyberte podsíť —</option>
                @foreach($availableSubnets as $subnet)
                    <option value="{{ $subnet->id }}">
                        {{ $subnet->name }}
                        ({{ $subnet->network_address }}/{{ $subnet->netmask }})
                    </option>
                @endforeach
            </select>
            <button type="submit">
                <img src="{{ asset('media/images/icons/ico_add.gif') }}" alt="">
                Přidat novou podsíť
            </button>
            @error('subnet_id')
                <span class="error">{{ $message }}</span>
            @enderror
        </form>
    @endif

    <table class="extended" cellspacing="0">
        <thead>
            <tr>
                <th>Název podsítě</th>
                <th>Adresa sítě</th>
                <th class="center">Zapnuto</th>
                <th>Akce</th>
            </tr>
        </thead>
        <tbody>
            @forelse($allowedSubnets as $as)
                <tr>
                    <td>
                        <a href="{{ route('subnets.show', $as->subnet_id) }}">
                            {{ $as->subnet->name ?? '—' }}
                        </a>
                    </td>
                    <td>
                        {{ $as->subnet->network_address ?? '—' }}
                        / {{ $as->subnet->netmask ?? '' }}
                    </td>
                    <td class="center">
                        @if($canEdit)
                            <form method="POST"
                                  action="{{ route('allowed_subnets.toggle', $as->id) }}"
                                  style="display:inline;">
                                @csrf
                                <button type="submit"
                                        style="border:none;background:none;cursor:pointer;padding:0;font-size:16px;"
                                        title="{{ $as->enabled ? 'Zapnuto — kliknutím vypnout' : 'Vypnuto — kliknutím zapnout' }}">
                                    <span style="color:{{ $as->enabled ? 'green' : '#ccc' }};">
                                        {{ $as->enabled ? '✓' : '✗' }}
                                    </span>
                                </button>
                            </form>
                        @else
                            <span style="color:{{ $as->enabled ? 'green' : '#ccc' }};">
                                {{ $as->enabled ? '✓' : '✗' }}
                            </span>
                        @endif
                    </td>
                    <td class="action">
                        @if($canDelete)
                            <form method="POST"
                                  action="{{ route('allowed_subnets.destroy', $as->id) }}"
                                  style="display:inline;"
                                  onsubmit="return confirm('Odebrat podsíť {{ addslashes($as->subnet->name ?? '') }}?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit"
                                        style="border:none;background:none;cursor:pointer;padding:0;"
                                        title="Odebrat">
                                    <img src="{{ asset('media/images/icons/delete.png') }}" alt="Odebrat">
                                </button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" style="text-align:center;">Žádné povolené podsítě.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection
