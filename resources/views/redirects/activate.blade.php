@extends('layouts.app')
@section('title', 'Aktivovat přesměrování')
@section('menu') <x-freenetis-menu /> @endsection
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
<div class="m-page">
<div class="m-title-row"><h2>Aktivovat přesměrování</h2></div>

@if($target === 'member' && $member)
<div class="m-alert m-alert-info" style="max-width:480px;margin-bottom:16px">
    Zpráva bude aktivována pro <strong>všechny IP adresy</strong> člena <strong>{{ $member->name }}</strong>.
</div>
@else
<div class="m-alert m-alert-info" style="max-width:480px;margin-bottom:16px">
    Zpráva bude aktivována pro IP adresu <strong style="font-family:monospace">{{ $ip->ip_address }}</strong>.
</div>
@endif

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

<form method="POST" action="{{ $formUrl }}">
@csrf
<div class="m-card" style="margin-bottom:16px;max-width:420px">
    <div class="m-form-group">
        <label class="m-form-label">Zpráva / přesměrování</label>
        <select class="m-form-select" name="message_id" required>
            <option value="">— vyberte —</option>
            @foreach($messages as $id => $name)
                <option value="{{ $id }}" @selected(old('message_id') == $id)>{{ $name }}</option>
            @endforeach
        </select>
    </div>
    <div class="m-form-group">
        <label class="m-form-label">Komentář admina</label>
        <textarea class="m-form-input" name="comment" rows="3">{{ old('comment') }}</textarea>
    </div>
</div>
<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Aktivovat přesměrování</button>
    <a class="m-btn" href="{{ $backUrl }}">Zrušit</a>
</div>
</form>
</div>
@endsection
