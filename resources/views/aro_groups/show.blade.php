@extends('layouts.app')
@section('title', 'Skupina: ' . $group->name)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('aro-groups.index') }}">Přístupová práva</a> » {{ $group->name }}
    </div>
@endsection
@section('content')
    <h2>Skupina: {{ $group->name }}</h2>

    <p>
        <a href="{{ route('aro-groups.edit', $group->id) }}">Upravit</a>
    </p>

    <table class="extended" cellspacing="0">
        <tr><th>ID</th><td>{{ $group->id }}</td></tr>
        <tr><th>Název</th><td>{{ $group->name }}</td></tr>
        <tr><th>Nadřazená skupina</th><td>{{ $group->parent?->name ?? '—' }}</td></tr>
        @if($group->children->count())
        <tr>
            <th>Podskupiny</th>
            <td>
                @foreach($group->children as $child)
                    <a href="{{ route('aro-groups.show', $child->id) }}">{{ $child->name }}</a>
                    @if(!$loop->last), @endif
                @endforeach
            </td>
        </tr>
        @endif
    </table>

    <h3>Uživatelé ve skupině</h3>
    @if($users->isEmpty())
        <p>Žádní uživatelé.</p>
    @else
        <table class="extended" cellspacing="0">
            <thead><tr><th>ID</th><th>Uživatelské jméno</th></tr></thead>
            <tbody>
                @foreach($users as $user)
                    <tr>
                        <td>{{ $user->id }}</td>
                        <td><a href="{{ route('users.show', $user->id) }}">{{ $user->login }}</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h3>ACL pravidla skupiny</h3>
    @if($aclRules->isEmpty())
        <p>Žádná pravidla.</p>
    @else
        <table class="extended" cellspacing="0">
            <thead>
                <tr>
                    <th>ACL ID</th>
                    <th>Poznámka</th>
                    <th>Sekce / Objekt</th>
                </tr>
            </thead>
            <tbody>
                @foreach($aclRules as $aclId => $rows)
                    @foreach($rows as $i => $row)
                        <tr>
                            @if($i === 0)
                                <td rowspan="{{ count($rows) }}">{{ $row->acl_id }}</td>
                                <td rowspan="{{ count($rows) }}">{{ $row->note }}</td>
                            @endif
                            <td>{{ $row->section }} / {{ $row->resource }}</td>
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
