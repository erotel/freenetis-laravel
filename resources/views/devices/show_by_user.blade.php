@extends('layouts.app')

@section('title', 'Zařízení: ' . $user->full_name)

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('users.index') }}">Uživatelé</a> &raquo;
        <a href="{{ route('users.show', $user->id) }}">{{ $user->full_name }}</a> &raquo;
        Zařízení
    </div>
@endsection

@section('content')
    <h2>Zařízení uživatele {{ $user->full_name }}</h2>

    @if(session('success'))
        <p class="success">{{ session('success') }}</p>
    @endif
    @if(session('error'))
        <p class="error">{{ session('error') }}</p>
    @endif

    @if($canNew)
        <p>
            <a href="{{ route('devices.add', $user->id) }}">
                <img src="{{ asset('media/images/icons/ico_add.gif') }}" alt="Přidat">
                Přidat zařízení
            </a>
        </p>
    @endif

    @if($devices->isEmpty())
        <p>Žádná zařízení.</p>
    @else
        <table class="extended" cellspacing="0">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Název</th>
                    <th>Typ</th>
                    <th>Akce</th>
                </tr>
            </thead>
            <tbody>
                @foreach($devices as $device)
                    <tr>
                        <td>{{ $device->id }}</td>
                        <td><a href="{{ route('devices.show', $device->id) }}">{{ $device->name }}</a></td>
                        <td>{{ $device->enumType?->value ?? '—' }}</td>
                        <td>
                            <a href="{{ route('devices.show', $device->id) }}" title="Detail">
                                <img src="{{ asset('media/images/icons/con_info.png') }}" alt="Detail">
                            </a>
                            @if($canEdit)
                                <a href="{{ route('devices.edit', $device->id) }}" title="Upravit">
                                    <img src="{{ asset('media/images/icons/gtk_edit.png') }}" alt="Upravit">
                                </a>
                            @endif
                            @if($canDelete)
                                <form method="POST" action="{{ route('devices.destroy', $device->id) }}" style="display:inline;"
                                      onsubmit="return confirm('Opravdu smazat zařízení {{ addslashes($device->name) }}?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="icon-button" title="Smazat">
                                        <img src="{{ asset('media/images/icons/delete.png') }}" alt="Smazat">
                                    </button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
