@extends('layouts.app')
@section('title', 'Přístupová práva – skupiny')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">Přístupová práva</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Skupiny přístupových práv</h2></div>

<div class="m-actions">
    <a class="m-btn m-btn-success" href="{{ route('aro-groups.create') }}">+ Přidat skupinu</a>
    <a class="m-btn" href="{{ route('acl.create') }}">+ Přidat ACL pravidlo</a>
</div>

<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px">ID</th>
            <th>Název skupiny</th>
            <th style="width:160px">Nadřazená skupina</th>
            <th style="width:120px">Akce</th>
        </tr>
    </thead>
    <tbody>
        @foreach($groups as $group)
        <tr>
            <td>{{ $group->id }}</td>
            <td>
                @php $depth = 0; $p = $group->parent; while($p) { $depth++; $p = $p->parent; } @endphp
                @if($depth)<span style="display:inline-block;width:{{ $depth * 16 }}px"></span>@endif
                <a class="m-link" href="{{ route('aro-groups.show', $group->id) }}">{{ $group->name }}</a>
            </td>
            <td>{{ $group->parent?->name ?? '—' }}</td>
            <td>
                <div style="display:flex;gap:6px">
                    <a class="m-link-sm" href="{{ route('aro-groups.show', $group->id) }}">Detail</a>
                    <a class="m-link-sm" href="{{ route('aro-groups.edit', $group->id) }}">Upravit</a>
                    <form method="POST" action="{{ route('aro-groups.destroy', $group->id) }}" style="display:inline">
                        @csrf @method('DELETE')
                        <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;font-size:12px;color:#c0392b"
                                onclick="return confirm('Smazat skupinu?')">Smazat</button>
                    </form>
                </div>
            </td>
        </tr>
        @endforeach
    </tbody>
</table>
</div>
</div>
@endsection
