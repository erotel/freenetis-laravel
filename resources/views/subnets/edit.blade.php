@extends('layouts.app')
@section('title', 'Upravit subnet: ' . $subnet->label)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('subnets.index') }}">Subnety</a> &raquo;
    <a href="{{ route('subnets.show', $subnet->id) }}">{{ $subnet->label }}</a> &raquo;
    Upravit
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Upravit subnet: {{ $subnet->label }}</h2></div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

<form method="POST" action="{{ route('subnets.update', $subnet->id) }}">
@csrf
@method('PUT')
<div class="m-card" style="margin-bottom:16px;max-width:480px">
    <div class="m-form-group">
        <label class="m-form-label" for="name">Název</label>
        <input class="m-form-input" type="text" id="name" name="name"
               value="{{ old('name', $subnet->name) }}" maxlength="254">
        @error('name') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="network_address">Síťová adresa <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="text" id="network_address" name="network_address"
                   value="{{ old('network_address', $subnet->network_address) }}" maxlength="15" style="font-family:monospace">
            @error('network_address') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="netmask">Maska sítě <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="text" id="netmask" name="netmask"
                   value="{{ old('netmask', $subnet->netmask) }}" maxlength="15" style="font-family:monospace">
            @error('netmask') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
    <div style="display:flex;gap:20px;flex-wrap:wrap;margin-top:4px">
        @if($canSetDhcp)
        <label style="display:flex;align-items:center;gap:6px;font-size:16px;cursor:pointer">
            <input type="checkbox" id="dhcp" name="dhcp" value="1" @checked(old('dhcp', $subnet->dhcp))>
            DHCP server
        </label>
        @endif
        @if($canSetDns)
        <label style="display:flex;align-items:center;gap:6px;font-size:16px;cursor:pointer">
            <input type="checkbox" id="dns" name="dns" value="1" @checked(old('dns', $subnet->dns))>
            DNS server
        </label>
        @endif
        @if($canSetQos)
        <label style="display:flex;align-items:center;gap:6px;font-size:16px;cursor:pointer">
            <input type="checkbox" id="qos" name="qos" value="1" @checked(old('qos', $subnet->qos))>
            QoS
        </label>
        @endif
        @if($canSetDhcp)
        <label style="display:flex;align-items:center;gap:6px;font-size:16px;cursor:pointer" title="Přidělování IP podle line-id (option82) přes RADIUS místo statických MAC leasů. Zapínej jen na subnetech, kde jsou zákazníci přímo na managed switchi s option82.">
            <input type="hidden" name="ipoe" value="0">
            <input type="checkbox" id="ipoe" name="ipoe" value="1" @checked(old('ipoe', $subnet->ipoe))>
            IPoE (line-id)
        </label>
        @endif
    </div>
</div>
<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Uložit</button>
    <a class="m-btn" href="{{ route('subnets.show', $subnet->id) }}">Zrušit</a>
</div>
</form>
</div>
@endsection
