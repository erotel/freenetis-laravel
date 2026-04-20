@extends('layouts.app')
@section('title', 'DHCP servery')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('devices.index') }}">Zařízení</a> »
    <span>DHCP servery</span>
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>DHCP servery</h2></div>
<div class="m-subtitle">Zařízení s aktivním DHCP subnetem, řazená podle posledního stažení.</div>

<div class="m-card" style="margin-top:16px">
<table class="m-table">
    <thead>
        <tr>
            <th>ID</th>
            <th>Název</th>
            <th>Subnety</th>
            <th>Poslední stažení</th>
            <th>Stav</th>
            <th>Akce</th>
        </tr>
    </thead>
    <tbody>
    @forelse($devices as $device)
        <tr>
            <td>{{ $device->id }}</td>
            <td><a href="{{ route('devices.show', $device->id) }}">{{ $device->name }}</a></td>
            <td style="font-size:12px;color:#6b7280">{{ $device->subnets }}</td>
            <td>
                @if($device->access_time && $device->access_time !== '0000-00-00 00:00:00')
                    {{ \Carbon\Carbon::parse($device->access_time)->diffForHumans() }}
                @else
                    <span style="color:#9ca3af">nikdy</span>
                @endif
            </td>
            <td>
                @if($device->error)
                    <span class="m-tag m-tag-red">Chyba</span>
                @elseif(!$device->access_time || $device->access_time === '0000-00-00 00:00:00')
                    <span class="m-tag" style="background:#f3f4f6;color:#6b7280">Nikdy</span>
                @elseif($device->has_expired)
                    <span class="m-tag m-tag-amber">Změněno</span>
                @else
                    <span class="m-tag m-tag-green">Aktuální</span>
                @endif
            </td>
            <td style="white-space:nowrap">
                <a class="m-btn" style="font-size:12px;padding:4px 10px"
                   href="{{ route('devices.export', ['id' => $device->id, 'format' => 'mikrotik-ip-dhcp-server']) }}?token={{ $token }}">
                    Export
                </a>
                <a class="m-btn" style="font-size:12px;padding:4px 10px;margin-left:4px"
                   href="{{ route('devices.export', ['id' => $device->id, 'format' => 'mikrotik-ip-dhcp-server']) }}?token={{ $token }}&forced=1">
                    Forced
                </a>
            </td>
        </tr>
    @empty
        <tr><td colspan="6" style="text-align:center;color:#9ca3af">Žádná zařízení s DHCP subnetem.</td></tr>
    @endforelse
    </tbody>
</table>
</div>

@if($token)
<div class="m-card" style="margin-top:16px;max-width:640px">
    <div class="m-card-title">Export URL formát</div>
    <div class="m-form-hint" style="font-family:monospace;font-size:12px;word-break:break-all">
        /devices/{id}/export/mikrotik-ip-dhcp-server?token={{ $token }}<br>
        /devices/{id}/export/mikrotik-ip-dhcp-server?token={{ $token }}&forced=1
    </div>
</div>
@endif

</div>
@endsection
