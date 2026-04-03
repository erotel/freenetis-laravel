@extends('layouts.app')

@section('title', 'Detail města')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('towns.index') }}">Města</a> &raquo;
        Detail
    </div>
@endsection

@section('content')
    <h2>Detail města</h2>

    <table class="extended" cellspacing="0">
        <tbody>
            <tr>
                <th>ID</th>
                <td>{{ $town->id }}</td>
            </tr>
            <tr>
                <th>Město</th>
                <td>{{ $town->town }}</td>
            </tr>
            <tr>
                <th>Čtvrť</th>
                <td>{{ $town->quarter }}</td>
            </tr>
            <tr>
                <th>PSČ</th>
                <td>{{ $town->zip_code }}</td>
            </tr>
            <tr>
                <th>Počet adresních bodů</th>
                <td>{{ $town->addressPoints()->count() }}</td>
            </tr>
        </tbody>
    </table>

    @if($canEdit)
        <p>
            <a href="{{ route('towns.edit', $town->id) }}">
                <img src="{{ asset('media/images/icons/gtk_edit.png') }}" alt="Upravit">
                Upravit
            </a>
        </p>
    @endif

    <h3>Ulice</h3>

    <table class="extended" cellspacing="0">
        <thead>
            <tr>
                <th>ID</th>
                <th>Ulice</th>
                <th>Akce</th>
            </tr>
        </thead>
        <tbody>
            @forelse($streets as $street)
                <tr>
                    <td>{{ $street->id }}</td>
                    <td>{{ $street->street }}</td>
                    <td class="action">
                        <a href="#" title="Detail">
                            <img src="{{ asset('media/images/icons/con_info.png') }}" alt="Detail">
                        </a>
                        @if($canEdit)
                            <a href="#" title="Upravit">
                                <img src="{{ asset('media/images/icons/gtk_edit.png') }}" alt="Upravit">
                            </a>
                        @endif
                        @if($canDelete)
                            <span title="Smazat">
                                <img src="{{ asset('media/images/icons/delete.png') }}" alt="Smazat">
                            </span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="3">Žádné ulice.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection
