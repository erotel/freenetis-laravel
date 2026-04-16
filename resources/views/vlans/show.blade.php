@extends('layouts.app')
@section('title', 'VLAN: ' . $vlan)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('vlans.index') }}">VLANy</a> &raquo; {{ (string)$vlan }}
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>{{ (string)$vlan }}</h2></div>

<div class="m-actions">
    @if($canEdit) <a class="m-btn" href="{{ route('vlans.edit', $vlan->id) }}">Upravit</a> @endif
    @if($canDelete && $vlan->tag_802_1q !== \App\Models\Vlan::DEFAULT_VLAN_TAG)
    <form method="POST" action="{{ route('vlans.destroy', $vlan->id) }}" style="display:inline"
          onsubmit="return confirm('Opravdu smazat VLAN {{ addslashes((string)$vlan) }}?')">
        @csrf @method('DELETE')
        <button class="m-btn m-btn-danger" type="submit">Smazat</button>
    </form>
    @endif
</div>

<div class="m-card" style="max-width:360px;margin-bottom:16px">
    <div class="m-card-title">Informace o VLAN</div>
    <div class="m-field"><span class="m-field-label">ID</span><span class="m-field-value">{{ $vlan->id }}</span></div>
    <div class="m-field"><span class="m-field-label">Název</span><span class="m-field-value">{{ $vlan->name ?: '—' }}</span></div>
    <div class="m-field"><span class="m-field-label">802.1Q tag</span><span class="m-field-value">{{ $vlan->tag_802_1q }}</span></div>
    <div class="m-field"><span class="m-field-label">Komentář</span><span class="m-field-value">{{ $vlan->comment ?: '—' }}</span></div>
</div>

<div class="m-section">Přiřazená rozhraní</div>
@if($vlan->ifaces->isEmpty())
<div class="m-card"><div style="text-align:center;color:#aaa;padding:1.5rem">Žádná rozhraní.</div></div>
@else
<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px">ID</th>
            <th>Rozhraní</th>
            <th>Zařízení</th>
            <th style="width:70px">Tagged</th>
        </tr>
    </thead>
    <tbody>
        @foreach($vlan->ifaces as $iface)
        <tr>
            <td>{{ $iface->id }}</td>
            <td>{{ $iface->name ?? 'iface #' . $iface->id }}</td>
            <td>
                @if($iface->device)
                    @if($canViewDevices)
                        <a class="m-link" href="{{ route('devices.show', $iface->device_id) }}">{{ $iface->device->name }}</a>
                    @else
                        {{ $iface->device->name }}
                    @endif
                @else —
                @endif
            </td>
            <td>@if($iface->pivot->tagged)<span class="m-tag m-tag-green">Ano</span>@else —@endif</td>
        </tr>
        @endforeach
    </tbody>
</table>
</div>
@endif

</div>
@endsection
