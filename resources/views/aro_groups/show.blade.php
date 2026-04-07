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

    @if(session('success'))
        <p style="color:green">{{ session('success') }}</p>
    @endif
    @if(session('error'))
        <p style="color:red">{{ session('error') }}</p>
    @endif

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
            <thead><tr><th>ID</th><th>Uživatelské jméno</th><th>Akce</th></tr></thead>
            <tbody>
                @foreach($users as $user)
                    <tr>
                        <td>{{ $user->id }}</td>
                        <td><a href="{{ route('users.show', $user->id) }}">{{ $user->login }}</a></td>
                        <td>
                            <form method="POST" action="{{ route('aro-groups.remove-user', [$group->id, $user->id]) }}" style="display:inline">
                                @csrf @method('DELETE')
                                <button type="submit" onclick="return confirm('Odebrat uživatele ze skupiny?')">Odebrat</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <form method="POST" action="{{ route('aro-groups.add-user', $group->id) }}" style="margin-top:0.5em">
        @csrf
        <select name="user_id">
            <option value="">— vyberte uživatele —</option>
            @foreach($allUsers as $u)
                <option value="{{ $u->id }}">{{ $u->login }}</option>
            @endforeach
        </select>
        <button type="submit">Přidat uživatele</button>
    </form>

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
                    <th>Akce</th>
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
                            @if($i === 0)
                                <td rowspan="{{ count($rows) }}" style="white-space:nowrap;">
                                    <a href="{{ route('acl.edit', $aclId) }}">Upravit</a>
                                    |
                                    <form method="POST" action="{{ route('aro-groups.remove-acl', [$group->id, $aclId]) }}" style="display:inline">
                                        @csrf @method('DELETE')
                                        <button type="submit" onclick="return confirm('Odebrat ACL pravidlo skupině?')">Odebrat</button>
                                    </form>
                                    |
                                    <form method="POST" action="{{ route('acl.destroy', $aclId) }}" style="display:inline">
                                        @csrf @method('DELETE')
                                        <button type="submit" onclick="return confirm('Smazat ACL pravidlo {{ $aclId }} úplně?')">Smazat</button>
                                    </form>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    @endif

    <form method="POST" action="{{ route('aro-groups.add-acl', $group->id) }}" style="margin-top:0.5em">
        @csrf
        <select name="acl_id">
            <option value="">— vyberte ACL pravidlo —</option>
            @foreach($allAcls as $acl)
                <option value="{{ $acl->id }}">{{ $acl->id }} – {{ $acl->note }}</option>
            @endforeach
        </select>
        <button type="submit">Přiřadit pravidlo</button>
    </form>
@endsection
