@extends('layouts.app')
@section('title', 'Přístupová práva – skupiny')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
    <div id="breadcrumbs">Přístupová práva</div>
@endsection
@section('content')
    <h2>Skupiny přístupových práv</h2>

    @if(session('success'))
        <div class="message success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="message error">{{ session('error') }}</div>
    @endif

    <div style="margin-bottom:1em;">
        <a href="{{ route('aro-groups.create') }}">+ Přidat skupinu</a>
        &nbsp;|&nbsp;
        <a href="{{ route('acl.create') }}">+ Přidat ACL pravidlo</a>
    </div>

    <table class="extended" cellspacing="0">
        <thead>
            <tr>
                <th>ID</th>
                <th>Název skupiny</th>
                <th>Nadřazená skupina</th>
                <th>Akce</th>
            </tr>
        </thead>
        <tbody>
            @foreach($groups as $group)
                <tr>
                    <td>{{ $group->id }}</td>
                    <td>
                        @php $depth = 0; $p = $group->parent; while($p) { $depth++; $p = $p->parent; } @endphp
                        {!! str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $depth) !!}
                        <a href="{{ route('aro-groups.show', $group->id) }}">{{ $group->name }}</a>
                    </td>
                    <td>{{ $group->parent?->name ?? '—' }}</td>
                    <td>
                        <a href="{{ route('aro-groups.show', $group->id) }}">Detail</a>
                        | <a href="{{ route('aro-groups.edit', $group->id) }}">Upravit</a>
                        | <form method="POST" action="{{ route('aro-groups.destroy', $group->id) }}" style="display:inline">
                            @csrf @method('DELETE')
                            <button type="submit" onclick="return confirm('Smazat skupinu?')">Smazat</button>
                          </form>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endsection
