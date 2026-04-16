@extends('layouts.app')
@section('title', 'Zařízení: ' . $user->full_name)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('users.index') }}">Uživatelé</a> &raquo;
    <a href="{{ route('users.show', $user->id) }}">{{ $user->full_name }}</a> &raquo;
    Zařízení
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Zařízení uživatele {{ $user->full_name }}</h2></div>

@if($canNew)
<div class="m-actions">
    <a class="m-btn m-btn-success" href="{{ route('devices.add', $user->id) }}">+ Přidat zařízení</a>
</div>
@endif

@if($devices->isEmpty())
<div class="m-card">
    <div style="text-align:center;color:#aaa;padding:2rem">Žádná zařízení.</div>
</div>
@else
<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px">ID</th>
            <th>Název</th>
            <th style="width:130px">Typ</th>
            <th style="width:100px">Akce</th>
        </tr>
    </thead>
    <tbody>
        @foreach($devices as $device)
        <tr>
            <td>{{ $device->id }}</td>
            <td><a class="m-link" href="{{ route('devices.show', $device->id) }}">{{ $device->name }}</a></td>
            <td>{{ $device->enumType?->value ?? '—' }}</td>
            <td>
                <div style="display:flex;gap:6px">
                    <a class="m-link-sm" href="{{ route('devices.show', $device->id) }}">Detail</a>
                    @if($canEdit)
                    <a class="m-link-sm" href="{{ route('devices.edit', $device->id) }}">Upravit</a>
                    @endif
                    @if($canDelete)
                    <form method="POST" action="{{ route('devices.destroy', $device->id) }}" style="display:inline"
                          onsubmit="return confirm('Opravdu smazat zařízení {{ addslashes($device->name) }}?')">
                        @csrf @method('DELETE')
                        <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;font-size:12px;color:#c0392b">Smazat</button>
                    </form>
                    @endif
                </div>
            </td>
        </tr>
        @endforeach
    </tbody>
</table>
</div>
@endif
</div>
@endsection
