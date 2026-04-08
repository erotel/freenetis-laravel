@extends('layouts.app')
@section('title', 'Typy')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
    <div id="breadcrumbs">Administrace &raquo; Typy</div>
@endsection
@section('content')
    <h2>Typy</h2>

    @if(session('success'))
        <div class="message success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="message error">{{ session('error') }}</div>
    @endif

    <div style="margin-bottom:1em;">
        <a href="{{ route('enum-types.create') }}">+ Přidat typ</a>
    </div>

    @foreach($groups as $group)
        <h3>{{ $group->type_name }}</h3>
        <table class="extended" cellspacing="0">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Název</th>
                    <th>Systémový</th>
                    <th>Akce</th>
                </tr>
            </thead>
            <tbody>
                @foreach($group->enumTypes as $type)
                    <tr>
                        <td>{{ $type->id }}</td>
                        <td>{{ $type->value }}</td>
                        <td>{{ $type->read_only ? '✓' : '' }}</td>
                        <td>
                            @if(!$type->read_only)
                                <a href="{{ route('enum-types.edit', $type->id) }}">Upravit</a>
                                | <form method="POST" action="{{ route('enum-types.destroy', $type->id) }}" style="display:inline">
                                    @csrf @method('DELETE')
                                    <button type="submit" onclick="return confirm('Smazat typ {{ $type->value }}?')">Smazat</button>
                                  </form>
                            @else
                                <span style="color:#999">systémový</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endforeach
@endsection
