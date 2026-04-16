@extends('layouts.app')
@section('title', 'Ukončit členství')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('members.index') }}">Členové</a> &raquo;
    <a href="{{ route('members.show', $member->id) }}">{{ $member->name }}</a> &raquo;
    Ukončit členství
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Ukončit členství: {{ $member->name }}</h2></div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">
        @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
    </ul>
</div>
@endif

<form method="POST" action="{{ route('members.end-membership.store', $member->id) }}">
@csrf

<div class="m-card" style="margin-bottom:16px;max-width:560px">
    <div class="m-form-group">
        <label class="m-form-label" for="leaving_date">Datum vystoupení <span style="color:#c0392b">*</span></label>
        <input class="m-form-input" type="date" id="leaving_date" name="leaving_date"
               value="{{ old('leaving_date', $today) }}" style="max-width:180px">
        @error('leaving_date') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    <div class="m-form-group">
        <label class="m-form-label" for="end_mode">Způsob ukončení <span style="color:#c0392b">*</span></label>
        <select class="m-form-select" name="end_mode" id="end_mode" onchange="toggleRefund(this.value)">
            <option value="4" {{ old('end_mode') == 4 ? 'selected' : '' }}>Ukončit na vlastní žádost (email)</option>
            <option value="2" {{ old('end_mode') == 2 ? 'selected' : '' }}>Ukončit pro neplacení (email)</option>
            <option value="3" {{ old('end_mode') == 3 ? 'selected' : '' }}>Ukončit s vratkou na č.ú. (email + platba)</option>
            <option value="1" {{ old('end_mode') == 1 ? 'selected' : '' }}>Ukončit bez emailu</option>
        </select>
        @error('end_mode') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    <div id="refund-row" style="display:none">
        <div class="m-form-group">
            <label class="m-form-label" for="refund_account">Číslo účtu (pro vratku)</label>
            <input class="m-form-input" type="text" id="refund_account" name="refund_account"
                   value="{{ old('refund_account', $refundAccount) }}" placeholder="123456789/0300">
            @error('refund_account') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="refund_amount">Částka (Kč)</label>
            <input class="m-form-input" type="text" id="refund_amount" name="refund_amount"
                   value="{{ old('refund_amount', number_format((float)$balance, 2, '.', '')) }}" style="max-width:140px">
            <div class="m-form-hint">Aktuální zůstatek: {{ number_format((float)$balance, 2, ',', ' ') }} Kč</div>
            @error('refund_amount') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
</div>

<div class="m-actions">
    <button class="m-btn m-btn-danger" type="submit"
            onclick="return confirm('Opravdu ukončit členství pro {{ addslashes($member->name) }}?')">
        Ukončit členství
    </button>
    <a class="m-btn" href="{{ route('members.show', $member->id) }}">Zrušit</a>
</div>
</form>

<script>
function toggleRefund(mode) {
    const show = mode === '3';
    document.getElementById('refund-row').style.display = show ? '' : 'none';
}
document.addEventListener('DOMContentLoaded', () => toggleRefund(document.getElementById('end_mode').value));
</script>
</div>
@endsection
