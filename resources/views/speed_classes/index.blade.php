@extends('layouts.app')

@section('title', 'Třídy rychlosti')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('speed_classes.index') }}">Třídy rychlosti</a>
    </div>
@endsection

@section('content')
    <h2>Třídy rychlosti</h2>

    @if($canNew)
        <p>
            <a href="{{ route('speed_classes.create') }}">
                <img src="{{ asset('media/images/icons/ico_add.gif') }}" alt="Přidat">
                Přidat novou třídu rychlosti
            </a>
        </p>
    @endif

    <table class="extended" cellspacing="0">
        <thead>
            <tr>
                <th>ID</th>
                <th>Jméno</th>
                <th title="Maximální přenosová rychlost Download/Upload">Max. rychlost (D/U)</th>
                <th title="Garantovaná minimální rychlost Download/Upload">Min. rychlost (D/U)</th>
                <th title="Počet přiřazených členů">Počet členů</th>
                <th title="Výchozí pro řadové členy">MD</th>
                <th title="Výchozí pro žadatele">AD</th>
                <th>Akce</th>
            </tr>
        </thead>
        <tbody>
            @forelse($speedClasses as $sc)
                <tr>
                    <td>{{ $sc->id }}</td>
                    <td>{{ $sc->name }}</td>
                    <td>{{ \App\Models\SpeedClass::formatPair($sc->d_ceil, $sc->u_ceil) }}</td>
                    <td>{{ \App\Models\SpeedClass::formatPair($sc->d_rate, $sc->u_rate) }}</td>
                    <td class="center">{{ $sc->members_count }}</td>
                    <td class="center">
                        @if($canEdit)
                            <form method="POST"
                                  action="{{ route('speed_classes.set_default', [$sc->id, 'member']) }}"
                                  style="display:inline">
                                @csrf
                                <button type="submit"
                                        style="border:none;background:none;cursor:pointer;padding:0;"
                                        title="{{ $sc->regular_member_default ? 'Zrušit výchozí pro členy' : 'Nastavit jako výchozí pro členy' }}">
                                    <span style="color:{{ $sc->regular_member_default ? 'green' : '#ccc' }};font-size:16px;">●</span>
                                </button>
                            </form>
                        @else
                            <span style="color:{{ $sc->regular_member_default ? 'green' : '#ccc' }}">●</span>
                        @endif
                    </td>
                    <td class="center">
                        @if($canEdit)
                            <form method="POST"
                                  action="{{ route('speed_classes.set_default', [$sc->id, 'applicant']) }}"
                                  style="display:inline">
                                @csrf
                                <button type="submit"
                                        style="border:none;background:none;cursor:pointer;padding:0;"
                                        title="{{ $sc->applicant_default ? 'Zrušit výchozí pro žadatele' : 'Nastavit jako výchozí pro žadatele' }}">
                                    <span style="color:{{ $sc->applicant_default ? 'green' : '#ccc' }};font-size:16px;">●</span>
                                </button>
                            </form>
                        @else
                            <span style="color:{{ $sc->applicant_default ? 'green' : '#ccc' }}">●</span>
                        @endif
                    </td>
                    <td class="action">
                        @if($canEdit)
                            <a href="{{ route('speed_classes.edit', $sc->id) }}" title="Upravit">
                                <img src="{{ asset('media/images/icons/gtk_edit.png') }}" alt="Upravit">
                            </a>
                        @endif
                        @if($canDel)
                            <form method="POST"
                                  action="{{ route('speed_classes.destroy', $sc->id) }}"
                                  style="display:inline"
                                  onsubmit="return confirm('Smazat třídu {{ addslashes($sc->name) }}?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" style="border:none;background:none;cursor:pointer;padding:0">
                                    <img src="{{ asset('media/images/icons/delete.png') }}" alt="Smazat">
                                </button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" style="text-align:center;">Žádné třídy rychlosti.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection
