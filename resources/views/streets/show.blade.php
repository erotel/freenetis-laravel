@extends('layouts.app')
@section('title', 'Detail ulice')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('streets.index') }}">Ulice</a> &raquo; {{ $street->street }}
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>{{ $street->street }}</h2></div>

<div class="m-actions">
    @if($canEdit) <a class="m-btn" href="{{ route('streets.edit', $street->id) }}">Upravit</a> @endif
</div>

<div class="m-card" style="max-width:360px">
    <div class="m-card-title">Informace o ulici</div>
    <div class="m-field"><span class="m-field-label">ID</span><span class="m-field-value">{{ $street->id }}</span></div>
    <div class="m-field"><span class="m-field-label">Ulice</span><span class="m-field-value">{{ $street->street }}</span></div>
    <div class="m-field">
        <span class="m-field-label">Město</span>
        <span class="m-field-value">
            @if($street->town) <a href="{{ route('towns.show', $street->town_id) }}">{{ $street->town }}</a>
            @else — @endif
        </span>
    </div>
    <div class="m-field"><span class="m-field-label">Počet adresních bodů</span><span class="m-field-value">{{ $apTotal }}</span></div>
    <div class="m-field"><span class="m-field-label">Počet členů</span><span class="m-field-value">{{ $memberCount }}</span></div>
    <div class="m-field"><span class="m-field-label">Prázdných AP <small style="color:#888">(bez člena/zařízení)</small></span><span class="m-field-value">{{ $apOrphan }}</span></div>
</div>
</div>
@endsection
