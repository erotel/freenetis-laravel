@extends('layouts.app')

@section('title', 'Variabilní symboly: ' . $account->name)

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('members.index') }}">Členové</a> &raquo;
        @if($account->member)
            <a href="{{ route('members.show', $account->member_id) }}">{{ $account->member->name }}</a> &raquo;
        @endif
        <a href="{{ route('accounts.show', $account->id) }}">{{ $account->name }}</a> &raquo;
        Variabilní symboly
    </div>
@endsection

@section('content')
    <h2>Variabilní symboly: {{ $account->name }}</h2>

    <div style="margin-bottom:1em;">
        <a href="{{ route('accounts.show', $account->id) }}">&larr; Zpět na účet</a>
    </div>

    @if($canAdd)
        <h3>Přidat variabilní symbol</h3>
        <form method="POST" action="{{ route('variable_symbols.store', $account->id) }}" class="form" style="margin-bottom:1.5em;">
            @csrf
            <table class="extended" cellspacing="0">
                <tbody>
                    <tr>
                        <th><label for="variable_symbol">Variabilní symbol <span class="required">*</span></label></th>
                        <td>
                            <input type="text" id="variable_symbol" name="variable_symbol"
                                   value="{{ old('variable_symbol') }}" maxlength="10"
                                   placeholder="např. 103810">
                            <input type="submit" value="Přidat">
                            @error('variable_symbol') <span class="error">{{ $message }}</span> @enderror
                        </td>
                    </tr>
                </tbody>
            </table>
        </form>
    @endif

    <table class="extended" cellspacing="0">
        <thead>
            <tr>
                <th>ID</th>
                <th>Variabilní symbol</th>
                <th>Akce</th>
            </tr>
        </thead>
        <tbody>
            @forelse($symbols as $symbol)
                <tr>
                    <td>{{ $symbol->id }}</td>
                    <td>{{ $symbol->variable_symbol }}</td>
                    <td class="action">
                        @if($canDelete)
                            <form method="POST" action="{{ route('variable_symbols.destroy', $symbol->id) }}"
                                  style="display:inline;"
                                  onsubmit="return confirm('Opravdu smazat variabilní symbol {{ $symbol->variable_symbol }}?');">
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
                <tr>
                    <td colspan="3">Žádné variabilní symboly.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection
