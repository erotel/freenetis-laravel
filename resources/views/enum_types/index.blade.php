@extends('layouts.app')
@section('title', 'Typy')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">Administrace &raquo; Typy</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Typy</h2></div>

<div class="m-actions">
    <a class="m-btn m-btn-success" href="{{ route('enum-types.create') }}">+ Přidat typ</a>
</div>

@foreach($groups as $group)
<div class="m-section">{{ $group->type_name }}</div>
<div class="m-card" style="padding:0;overflow-x:auto;margin-bottom:16px">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px">ID</th>
            <th>Název</th>
            <th style="width:90px">Systémový</th>
            <th style="width:100px">Akce</th>
        </tr>
    </thead>
    <tbody>
        @foreach($group->enumTypes as $type)
        <tr>
            <td>{{ $type->id }}</td>
            <td>{{ $type->value }}</td>
            <td>@if($type->read_only)<span class="m-tag m-tag-gray">Systémový</span>@endif</td>
            <td>
                @if(!$type->read_only)
                <div style="display:flex;gap:6px">
                    <a class="m-link-sm" href="{{ route('enum-types.edit', $type->id) }}">Upravit</a>
                    <form method="POST" action="{{ route('enum-types.destroy', $type->id) }}" style="display:inline">
                        @csrf @method('DELETE')
                        <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;font-size:14px;color:#c0392b"
                                onclick="return confirm('Smazat typ {{ $type->value }}?')">Smazat</button>
                    </form>
                </div>
                @else
                <span style="color:#aaa;font-size:14px">systémový</span>
                @endif
            </td>
        </tr>
        @endforeach
    </tbody>
</table>
</div>
@endforeach
</div>
@endsection
