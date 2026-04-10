@extends('layouts.app')
@section('title', 'Vyhledávání')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
    <div id="breadcrumbs">Vyhledávání</div>
@endsection
@section('content')
<h2>Vyhledávání</h2>

<form method="GET" action="{{ route('search') }}" style="margin-bottom:1.5em;">
    <input type="text" name="q" value="{{ $query }}"
           placeholder="Hledat člena, IP, MAC, VS..."
           style="width:350px; padding:6px 10px; font-size:1em; border:1px solid #ccc; border-radius:4px;">
    <button type="submit" style="padding:6px 16px; margin-left:6px;">Hledat</button>
</form>

@if(strlen($query) > 0 && strlen($query) < 2)
    <p style="color:orange;">Zadejte alespoň 2 znaky.</p>
@elseif(strlen($query) >= 2 && empty($results))
    <p>Žádné výsledky pro <strong>{{ $query }}</strong>.</p>
@endif

{{-- MEMBERS --}}
@if(!empty($results['members']))
    <h3 style="color:#c00; margin-top:1.5em;">Členové ({{ count($results['members']) }})</h3>
    <table class="extended" cellspacing="0">
        <thead><tr>
            <th>ID</th><th>Jméno</th><th>Typ</th><th>Město</th><th>Variabilní symbol</th>
        </tr></thead>
        <tbody>
        @foreach($results['members'] as $m)
            <tr>
                <td>{{ $m->id }}</td>
                <td><a href="{{ route('members.show', $m->id) }}">{{ $m->name }}</a></td>
                <td>{{ \App\Helpers\MemberType::label($m->type) }}</td>
                <td>{{ $m->town ?? '—' }}</td>
                <td>{{ $m->variable_symbol ?? '—' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

{{-- USERS --}}
@if(!empty($results['users']))
    <h3 style="color:#c00; margin-top:1.5em;">Uživatelé ({{ count($results['users']) }})</h3>
    <table class="extended" cellspacing="0">
        <thead><tr>
            <th>Login</th><th>Jméno</th><th>Člen</th>
        </tr></thead>
        <tbody>
        @foreach($results['users'] as $u)
            <tr>
                <td>{{ $u->login }}</td>
                <td>{{ $u->name }} {{ $u->surname }}</td>
                <td><a href="{{ route('members.show', $u->member_id) }}">{{ $u->member_name }}</a></td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

{{-- DEVICES --}}
@if(!empty($results['devices']))
    <h3 style="color:#c00; margin-top:1.5em;">Zařízení / IP adresy ({{ count($results['devices']) }})</h3>
    <table class="extended" cellspacing="0">
        <thead><tr>
            <th>Zařízení</th><th>MAC</th><th>IP adresa</th><th>Člen</th>
        </tr></thead>
        <tbody>
        @foreach($results['devices'] as $d)
            <tr>
                <td><a href="{{ route('devices.show', $d->id) }}">{{ $d->device_name }}</a></td>
                <td>{{ $d->mac ?? '—' }}</td>
                <td>{{ $d->ip_address ?? '—' }}</td>
                <td><a href="{{ route('members.show', $d->member_id) }}">{{ $d->member_name }}</a></td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

{{-- SUBNETS --}}
@if(!empty($results['subnets']))
    <h3 style="color:#c00; margin-top:1.5em;">Subnety ({{ count($results['subnets']) }})</h3>
    <table class="extended" cellspacing="0">
        <thead><tr>
            <th>Název</th><th>Adresa sítě</th><th>Maska</th>
        </tr></thead>
        <tbody>
        @foreach($results['subnets'] as $s)
            <tr>
                <td><a href="{{ route('subnets.show', $s->id) }}">{{ $s->name }}</a></td>
                <td>{{ $s->network_address }}</td>
                <td>{{ $s->netmask }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif
@endsection
