@extends('layouts.app')
@section('title', 'Třídy rychlosti')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs"><a href="{{ route('speed_classes.index') }}">Třídy rychlosti</a></div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Třídy rychlosti</h2></div>

@if($canNew)
<div class="m-actions">
    <a class="m-btn m-btn-success" href="{{ route('speed_classes.create') }}">+ Přidat třídu rychlosti</a>
</div>
@endif

<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px">ID</th>
            <th>Jméno</th>
            <th title="Maximální přenosová rychlost Download/Upload">Max. rychlost (D/U)</th>
            <th title="Garantovaná minimální rychlost Download/Upload">Min. rychlost (D/U)</th>
            <th title="Ceníková cena tarifu (Kč/měs)">Cena</th>
            <th style="width:80px" title="Počet přiřazených členů">Členů</th>
            <th style="width:50px" title="Výchozí pro řadové členy">MD</th>
            <th style="width:50px" title="Výchozí pro žadatele">AD</th>
            <th style="width:80px">Akce</th>
        </tr>
    </thead>
    <tbody>
        @forelse($speedClasses as $sc)
        <tr>
            <td>{{ $sc->id }}</td>
            <td>{{ $sc->name }}</td>
            <td style="font-family:monospace;font-size:14px">{{ \App\Models\SpeedClass::formatPair($sc->d_ceil, $sc->u_ceil) }}</td>
            <td style="font-family:monospace;font-size:14px">{{ \App\Models\SpeedClass::formatPair($sc->d_rate, $sc->u_rate) }}</td>
            <td style="white-space:nowrap;{{ $sc->price === null ? 'color:#999' : '' }}">{{ $sc->priceLabel() }}</td>
            <td style="text-align:center">
                @if($sc->members_count > 0)
                <a class="m-link" href="{{ route('speed_classes.members', $sc->id) }}" title="Zobrazit členy s touto rychlostí">{{ $sc->members_count }}</a>
                @else
                <span style="color:#bbb">0</span>
                @endif
            </td>
            <td style="text-align:center">
                @if($canEdit)
                <form method="POST" action="{{ route('speed_classes.set_default', [$sc->id, 'member']) }}" style="display:inline">
                    @csrf
                    <button type="submit" style="border:none;background:none;cursor:pointer;padding:0"
                            title="{{ $sc->regular_member_default ? 'Zrušit výchozí pro členy' : 'Nastavit jako výchozí pro členy' }}">
                        <span style="color:{{ $sc->regular_member_default ? '#27ae60' : '#ddd' }};font-size:19px">●</span>
                    </button>
                </form>
                @else
                <span style="color:{{ $sc->regular_member_default ? '#27ae60' : '#ddd' }}">●</span>
                @endif
            </td>
            <td style="text-align:center">
                @if($canEdit)
                <form method="POST" action="{{ route('speed_classes.set_default', [$sc->id, 'applicant']) }}" style="display:inline">
                    @csrf
                    <button type="submit" style="border:none;background:none;cursor:pointer;padding:0"
                            title="{{ $sc->applicant_default ? 'Zrušit výchozí pro žadatele' : 'Nastavit jako výchozí pro žadatele' }}">
                        <span style="color:{{ $sc->applicant_default ? '#27ae60' : '#ddd' }};font-size:19px">●</span>
                    </button>
                </form>
                @else
                <span style="color:{{ $sc->applicant_default ? '#27ae60' : '#ddd' }}">●</span>
                @endif
            </td>
            <td>
                <div style="display:flex;gap:6px">
                    @if($canEdit)
                    <a class="m-link-sm" href="{{ route('speed_classes.edit', $sc->id) }}">Upravit</a>
                    @endif
                    @if($canDel)
                    <form method="POST" action="{{ route('speed_classes.destroy', $sc->id) }}" style="display:inline"
                          onsubmit="return confirm('Smazat třídu {{ addslashes($sc->name) }}?')">
                        @csrf @method('DELETE')
                        <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;font-size:14px;color:#c0392b">Smazat</button>
                    </form>
                    @endif
                </div>
            </td>
        </tr>
        @empty
        <tr><td colspan="8" style="text-align:center;color:#aaa;padding:2rem">Žádné třídy rychlosti.</td></tr>
        @endforelse
    </tbody>
</table>
</div>
</div>
@endsection
