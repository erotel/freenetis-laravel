@extends('layouts.app')
@section('title', 'Detail města')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('towns.index') }}">Města</a> &raquo; Detail
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>{{ $town->town }}{{ $town->quarter ? ' – ' . $town->quarter : '' }}</h2></div>

<div class="m-actions">
    @if($canEdit) <a class="m-btn" href="{{ route('towns.edit', $town->id) }}">Upravit</a> @endif
</div>

<div class="m-grid2">
    <div class="m-card">
        <div class="m-card-title">Informace o městě</div>
        <div class="m-field"><span class="m-field-label">ID</span><span class="m-field-value">{{ $town->id }}</span></div>
        <div class="m-field"><span class="m-field-label">Město</span><span class="m-field-value">{{ $town->town }}</span></div>
        <div class="m-field"><span class="m-field-label">Čtvrť</span><span class="m-field-value">{{ $town->quarter ?: '—' }}</span></div>
        <div class="m-field"><span class="m-field-label">PSČ</span><span class="m-field-value">{{ $town->zip_code }}</span></div>
        <div class="m-field"><span class="m-field-label">Počet adresních bodů</span><span class="m-field-value">{{ $town->addressPoints()->count() }}</span></div>
    </div>
    <div></div>
</div>

<div class="m-section">Ulice</div>
<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr><th style="width:50px">ID</th><th>Ulice</th><th style="width:80px">Akce</th></tr>
    </thead>
    <tbody>
        @forelse($streets as $street)
        <tr>
            <td>{{ $street->id }}</td>
            <td>{{ $street->street }}</td>
            <td>
                <div style="display:flex;gap:6px">
                    <a class="m-link-sm" href="{{ route('streets.show', $street->id) }}">Detail</a>
                    @if($canEdit)
                    <a class="m-link-sm" href="{{ route('streets.edit', $street->id) }}">Upravit</a>
                    @endif
                </div>
            </td>
        </tr>
        @empty
        <tr><td colspan="3" style="text-align:center;color:#aaa;padding:1.5rem">Žádné ulice.</td></tr>
        @endforelse
    </tbody>
</table>
</div>

</div>
@endsection
