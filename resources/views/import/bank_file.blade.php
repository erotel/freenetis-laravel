@extends('layouts.app')
@section('title', 'Import bankovního výpisu: ' . $account->name)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('bank_accounts.index') }}">Bankovní účty</a> &raquo;
    <a href="{{ route('bank_accounts.show', $account->id) }}">{{ $account->name }}</a> &raquo;
    Import výpisu
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Import bankovního výpisu: {{ $account->name }}</h2></div>
<div class="m-subtitle">Nahrajte výpis ve formátu FIO CSV (kódování UTF-8, oddělovač středník).</div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

<form method="POST" action="{{ route('import.bank_file', $account->id) }}" enctype="multipart/form-data">
@csrf
<div class="m-card" style="margin-bottom:16px;max-width:420px">
    <div class="m-form-group">
        <label class="m-form-label">Soubor CSV</label>
        <input type="file" name="csv_file" accept=".csv,.txt" required class="m-form-input" style="padding:5px 8px">
        @error('csv_file') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
</div>
<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Importovat</button>
    <a class="m-btn" href="{{ route('bank_accounts.show', $account->id) }}">Zrušit</a>
</div>
</form>
</div>
@endsection
