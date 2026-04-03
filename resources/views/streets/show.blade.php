@extends('layouts.app')

@section('title', 'Detail ulice')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('streets.index') }}">Ulice</a> &raquo;
        {{ $street->street }}
    </div>
@endsection

@section('content')
    <h2>Detail ulice</h2>

    <table class="extended" cellspacing="0">
        <tbody>
            <tr>
                <th>ID</th>
                <td>{{ $street->id }}</td>
            </tr>
            <tr>
                <th>Ulice</th>
                <td>{{ $street->street }}</td>
            </tr>
            <tr>
                <th>Město</th>
                <td>
                    @if($street->town)
                        <a href="{{ route('towns.show', $street->town_id) }}">{{ $street->town }}</a>
                    @endif
                </td>
            </tr>
            <tr>
                <th>Počet adresních bodů</th>
                <td>{{ $street->addressPoints()->count() }}</td>
            </tr>
        </tbody>
    </table>

    @if($canEdit)
        <p>
            <a href="{{ route('streets.edit', $street->id) }}">
                <img src="{{ asset('media/images/icons/gtk_edit.png') }}" alt="Upravit">
                Upravit
            </a>
        </p>
    @endif
@endsection
