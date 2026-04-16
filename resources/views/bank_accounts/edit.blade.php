@extends('layouts.app')
@section('title', 'Upravit bankovní účet: ' . $account->name)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('bank_accounts.index') }}">Bankovní účty</a> &raquo;
    <a href="{{ route('bank_accounts.show', $account->id) }}">{{ $account->name }}</a> &raquo;
    Upravit
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Upravit bankovní účet: {{ $account->name }}</h2></div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

<form method="POST" action="{{ route('bank_accounts.update', $account->id) }}">
@csrf
@method('PUT')
<div class="m-card" style="margin-bottom:16px;max-width:520px">
    <div class="m-form-group">
        <label class="m-form-label" for="IBAN">IBAN</label>
        <input class="m-form-input" type="text" name="IBAN" id="IBAN"
               value="{{ old('IBAN', $account->IBAN) }}" maxlength="34" style="font-family:monospace">
        @error('IBAN') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    <div class="m-form-group">
        <label class="m-form-label" for="SWIFT">SWIFT</label>
        <input class="m-form-input" type="text" name="SWIFT" id="SWIFT"
               value="{{ old('SWIFT', $account->SWIFT) }}" maxlength="11" style="font-family:monospace;max-width:160px">
        @error('SWIFT') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    <div class="m-form-group">
        <label class="m-form-label" for="fio_token">FIO API token</label>
        <input class="m-form-input" type="text" name="fio_token" id="fio_token"
               value="{{ old('fio_token', $fioToken) }}" maxlength="200"
               placeholder="Vložte token z FIO internet bankingu">
        <div class="m-form-hint">Token najdete v FIO Internet Bankingu → Nastavení → API</div>
        @error('fio_token') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
</div>
<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Uložit</button>
    <a class="m-btn" href="{{ route('bank_accounts.show', $account->id) }}">Zrušit</a>
</div>
</form>
</div>
@endsection
