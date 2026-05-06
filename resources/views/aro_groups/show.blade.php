@extends('layouts.app')
@section('title', 'Skupina: ' . $group->name)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('aro-groups.index') }}">Přístupová práva</a> &raquo; {{ $group->name }}
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Skupina: {{ $group->name }}</h2></div>

<div class="m-actions">
    <a class="m-btn" href="{{ route('aro-groups.edit', $group->id) }}">Upravit</a>
</div>

<div class="m-card" style="max-width:420px;margin-bottom:16px">
    <div class="m-card-title">Informace o skupině</div>
    <div class="m-field"><span class="m-field-label">ID</span><span class="m-field-value">{{ $group->id }}</span></div>
    <div class="m-field"><span class="m-field-label">Název</span><span class="m-field-value">{{ $group->name }}</span></div>
    <div class="m-field"><span class="m-field-label">Nadřazená skupina</span><span class="m-field-value">{{ $group->parent?->name ?? '—' }}</span></div>
    @if($group->children->count())
    <div class="m-field">
        <span class="m-field-label">Podskupiny</span>
        <span class="m-field-value">
            @foreach($group->children as $child)
                <a class="m-link" href="{{ route('aro-groups.show', $child->id) }}">{{ $child->name }}</a>@if(!$loop->last), @endif
            @endforeach
        </span>
    </div>
    @endif
</div>

<div class="m-section">Uživatelé ve skupině</div>
@if($users->isEmpty())
<div class="m-alert m-alert-info">Žádní uživatelé.</div>
@else
<div class="m-card" style="padding:0;overflow-x:auto;margin-bottom:16px">
<table class="m-table" style="margin-bottom:0">
    <thead><tr><th style="width:50px">ID</th><th>Uživatelské jméno</th><th style="width:80px">Akce</th></tr></thead>
    <tbody>
        @foreach($users as $user)
        <tr>
            <td>{{ $user->id }}</td>
            <td><a class="m-link" href="{{ route('users.show', $user->id) }}">{{ $user->login }}</a></td>
            <td>
                <form method="POST" action="{{ route('aro-groups.remove-user', [$group->id, $user->id]) }}" style="display:inline">
                    @csrf @method('DELETE')
                    <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;font-size:14px;color:#c0392b"
                            onclick="return confirm('Odebrat uživatele ze skupiny?')">Odebrat</button>
                </form>
            </td>
        </tr>
        @endforeach
    </tbody>
</table>
</div>
@endif

<form method="POST" action="{{ route('aro-groups.add-user', $group->id) }}" style="display:flex;gap:8px;margin-bottom:16px">
    @csrf
    <select class="m-form-select" name="user_id" style="max-width:260px">
        <option value="">— vyberte uživatele —</option>
        @foreach($allUsers as $u)
            <option value="{{ $u->id }}">{{ $u->login }}</option>
        @endforeach
    </select>
    <button class="m-btn m-btn-success" type="submit">Přidat uživatele</button>
</form>

<div class="m-section">ACL pravidla skupiny</div>
@if($aclRules->isEmpty())
<div class="m-alert m-alert-info">Žádná pravidla.</div>
@else
<div class="m-card" style="padding:0;overflow-x:auto;margin-bottom:16px">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:70px">ACL ID</th>
            <th>Poznámka</th>
            <th>Sekce / Objekt</th>
            <th style="width:130px">Akce</th>
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
                <td style="font-size:14px">{{ $row->section }} / {{ $row->resource }}</td>
                @if($i === 0)
                <td rowspan="{{ count($rows) }}">
                    <div style="display:flex;gap:6px;flex-wrap:wrap">
                        <a class="m-link-sm" href="{{ route('acl.edit', $aclId) }}">Upravit</a>
                        <form method="POST" action="{{ route('aro-groups.remove-acl', [$group->id, $aclId]) }}" style="display:inline">
                            @csrf @method('DELETE')
                            <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;font-size:14px;color:#c0392b"
                                    onclick="return confirm('Odebrat ACL pravidlo skupině?')">Odebrat</button>
                        </form>
                        <form method="POST" action="{{ route('acl.destroy', $aclId) }}" style="display:inline">
                            @csrf @method('DELETE')
                            <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;font-size:14px;color:#c0392b"
                                    onclick="return confirm('Smazat ACL pravidlo {{ $aclId }} úplně?')">Smazat</button>
                        </form>
                    </div>
                </td>
                @endif
            </tr>
            @endforeach
        @endforeach
    </tbody>
</table>
</div>
@endif

<form method="POST" action="{{ route('aro-groups.add-acl', $group->id) }}" style="display:flex;gap:8px">
    @csrf
    <select class="m-form-select" name="acl_id" style="max-width:320px">
        <option value="">— vyberte ACL pravidlo —</option>
        @foreach($allAcls as $acl)
            <option value="{{ $acl->id }}">{{ $acl->id }} – {{ $acl->note }}</option>
        @endforeach
    </select>
    <button class="m-btn m-btn-success" type="submit">Přiřadit pravidlo</button>
</form>

</div>
@endsection
