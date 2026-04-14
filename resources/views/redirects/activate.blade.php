@extends('layouts.app')

@section('title', 'Aktivovat přesměrování')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        @if($target === 'member' && $member)
            <a href="{{ route('members.index') }}">Členové</a> &raquo;
            <a href="{{ route('members.show', $member->id) }}">{{ $member->name }}</a> &raquo;
        @else
            <a href="{{ route('ip_addresses.index') }}">IP adresy</a> &raquo;
            <a href="{{ route('ip_addresses.show', $ip->id) }}">{{ $ip->ip_address }}</a> &raquo;
        @endif
        Aktivovat přesměrování
    </div>
@endsection

@section('content')
<h2>Aktivovat přesměrování</h2>

@if($target === 'member' && $member)
    <p>Zpráva bude aktivována pro <strong>všechny IP adresy</strong> člena <strong>{{ $member->name }}</strong>.</p>
@else
    <p>Zpráva bude aktivována pro IP adresu <strong>{{ $ip->ip_address }}</strong>.</p>
@endif

@if($errors->any())
    <div class="message_error">
        <ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

<form method="POST" action="{{ $formUrl }}">
    @csrf

    <table class="extended" cellspacing="0" style="width:400px;">
        <tbody>
            <tr>
                <th>Zpráva / přesměrování</th>
                <td>
                    <select name="message_id" required style="width:100%;">
                        <option value="">— vyberte —</option>
                        @foreach($messages as $id => $name)
                            <option value="{{ $id }}" @selected(old('message_id') == $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                </td>
            </tr>
            <tr>
                <th>Komentář admina</th>
                <td>
                    <textarea name="comment" rows="3" style="width:100%;">{{ old('comment') }}</textarea>
                </td>
            </tr>
        </tbody>
    </table>

    <p>
        <button type="submit">Aktivovat přesměrování</button>
        &nbsp;
        <a href="{{ $backUrl }}">Zpět</a>
    </p>
</form>
@endsection
