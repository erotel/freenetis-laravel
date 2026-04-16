@extends('layouts.app')
@section('title', 'Schválit žádost #' . $cr->id)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('connection_requests.index') }}">Žádosti o připojení</a> &raquo;
    <a href="{{ route('connection_requests.show', $cr->id) }}">#{{ $cr->id }}</a> &raquo;
    Schválit
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Schválit žádost #{{ $cr->id }}</h2></div>

<div class="m-alert m-alert-info" style="margin-bottom:16px;max-width:480px">
    IP: <strong style="font-family:monospace">{{ $cr->ip_address }}</strong>,
    MAC: <strong style="font-family:monospace">{{ $cr->mac_address }}</strong>
</div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

<form method="POST" action="{{ route('connection_requests.approve', $cr->id) }}">
@csrf
<div class="m-card" style="margin-bottom:16px;max-width:420px">
    <div class="m-form-group">
        <label class="m-form-label">Šablona zařízení <span style="color:#c0392b">*</span></label>
        <select class="m-form-select" name="device_template_id" required>
            <option value="">— vyberte šablonu —</option>
            @foreach($templates as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
    </div>
</div>
<div class="m-actions">
    <button class="m-btn m-btn-success" type="submit">Pokračovat</button>
    <a class="m-btn" href="{{ route('connection_requests.show', $cr->id) }}">Zrušit</a>
</div>
</form>
</div>
@endsection
