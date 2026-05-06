@extends('layouts.app')
@section('title', 'Upravit IP adresu: ' . $ip->ip_address)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('ip_addresses.index') }}">IP adresy</a> &raquo;
    <a href="{{ route('ip_addresses.show', $ip->id) }}">{{ $ip->ip_address }}</a> &raquo;
    Upravit
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Upravit IP adresu: <span style="font-family:monospace">{{ $ip->ip_address }}</span></h2></div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

<form method="POST" action="{{ route('ip_addresses.update', $ip->id) }}">
@csrf
@method('PUT')
<div class="m-card" style="margin-bottom:16px;max-width:480px">
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="ip_address">IP adresa <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="text" id="ip_address" name="ip_address"
                   value="{{ old('ip_address', $ip->ip_address) }}" maxlength="15" style="font-family:monospace">
            @error('ip_address') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="subnet_id">Subnet <span style="color:#c0392b">*</span></label>
            <select class="m-form-select" id="subnet_id" name="subnet_id">
                <option value="">— vyberte subnet —</option>
                @foreach($subnets as $subnet)
                    <option value="{{ $subnet->id }}" @selected(old('subnet_id', $ip->subnet_id) == $subnet->id)>
                        {{ $subnet->label }}
                    </option>
                @endforeach
            </select>
            @error('subnet_id') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="iface_id">Rozhraní</label>
            <select class="m-form-select" id="iface_id" name="iface_id">
                <option value="">— žádné —</option>
                @foreach($ifaces->groupBy(fn($i) => $i->device?->name ?? '(bez zařízení)') as $deviceName => $deviceIfaces)
                    <optgroup label="{{ $deviceName }}">
                        @foreach($deviceIfaces as $iface)
                            <option value="{{ $iface->id }}" @selected(old('iface_id', $ip->iface_id) == $iface->id)>
                                {{ $iface->name ?? 'iface #' . $iface->id }}
                            </option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
            @error('iface_id') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="member_id">Člen</label>
            <select class="m-form-select" id="member_id" name="member_id">
                <option value="">— žádný —</option>
                @foreach($members as $member)
                    <option value="{{ $member->id }}" @selected(old('member_id', $ip->member_id) == $member->id)>
                        {{ $member->name }}
                    </option>
                @endforeach
            </select>
            @error('member_id') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
    <div style="display:flex;gap:20px;flex-wrap:wrap;margin-top:4px">
        <label style="display:flex;align-items:center;gap:6px;font-size:16px;cursor:pointer">
            <input type="checkbox" id="dhcp" name="dhcp" value="1" @checked(old('dhcp', $ip->dhcp))>
            DHCP
        </label>
        <label style="display:flex;align-items:center;gap:6px;font-size:16px;cursor:pointer">
            <input type="checkbox" id="gateway" name="gateway" value="1" @checked(old('gateway', $ip->gateway))>
            Gateway
        </label>
        <label style="display:flex;align-items:center;gap:6px;font-size:16px;cursor:pointer">
            <input type="checkbox" id="service" name="service" value="1" @checked(old('service', $ip->service))>
            Servisní adresa
        </label>
    </div>
</div>
<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Uložit</button>
    <a class="m-btn" href="{{ route('ip_addresses.show', $ip->id) }}">Zrušit</a>
</div>
</form>
</div>
@endsection
