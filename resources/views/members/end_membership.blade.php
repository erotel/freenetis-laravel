@extends('layouts.app')
@section('title', 'Ukončit členství')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('members.index') }}">Členové</a> »
        <a href="{{ route('members.show', $member->id) }}">{{ $member->name }}</a> »
        Ukončit členství
    </div>
@endsection
@section('content')
<h2>Ukončit členství: {{ $member->name }}</h2>

@if($errors->any())
    <div style="background:#f8d7da; border:1px solid #f5c6cb; padding:10px; margin-bottom:1em; border-radius:4px;">
        <ul style="margin:0; padding-left:1.2em;">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ route('members.end-membership.store', $member->id) }}">
    @csrf

    <table class="extended" cellspacing="0">
        <tr>
            <th><label for="leaving_date">Datum vystoupení <span style="color:red">*</span></label></th>
            <td>
                <input type="date" id="leaving_date" name="leaving_date"
                       value="{{ old('leaving_date', $today) }}" style="width:160px">
                @error('leaving_date') <span style="color:red">{{ $message }}</span> @enderror
            </td>
        </tr>
        <tr>
            <th><label for="end_mode">Způsob ukončení <span style="color:red">*</span></label></th>
            <td>
                <select name="end_mode" id="end_mode" onchange="toggleRefund(this.value)" style="width:420px">
                    <option value="4" {{ old('end_mode') == 4 ? 'selected' : '' }}>Ukončit na vlastní žádost (email)</option>
                    <option value="2" {{ old('end_mode') == 2 ? 'selected' : '' }}>Ukončit pro neplacení (email)</option>
                    <option value="3" {{ old('end_mode') == 3 ? 'selected' : '' }}>Ukončit s vratkou na č.ú. (email + platba)</option>
                    <option value="1" {{ old('end_mode') == 1 ? 'selected' : '' }}>Ukončit bez emailu</option>
                </select>
                @error('end_mode') <span style="color:red">{{ $message }}</span> @enderror
            </td>
        </tr>
        <tr id="refund-row" style="display:none;">
            <th><label for="refund_account">č.ú. (pro vratku)</label></th>
            <td>
                <input type="text" id="refund_account" name="refund_account"
                       value="{{ old('refund_account', $refundAccount) }}"
                       style="width:200px" placeholder="123456789/0300">
                @error('refund_account') <span style="color:red">{{ $message }}</span> @enderror
            </td>
        </tr>
        <tr id="amount-row" style="display:none;">
            <th><label for="refund_amount">Částka (Kč)</label></th>
            <td>
                <input type="text" id="refund_amount" name="refund_amount"
                       value="{{ old('refund_amount', number_format((float)$balance, 2, '.', '')) }}"
                       style="width:120px">
                <small style="color:#888;">Aktuální zůstatek: {{ number_format((float)$balance, 2, ',', ' ') }} Kč</small>
                @error('refund_amount') <span style="color:red">{{ $message }}</span> @enderror
            </td>
        </tr>
    </table>

    <div style="margin-top:1.5em;">
        <button type="submit" style="color:red;"
                onclick="return confirm('Opravdu ukončit členství pro {{ addslashes($member->name) }}?')">
            Ukončit členství
        </button>
        <a href="{{ route('members.show', $member->id) }}" style="margin-left:1em;">Zrušit</a>
    </div>
</form>

<script>
function toggleRefund(mode) {
    const show = mode === '3';
    document.getElementById('refund-row').style.display = show ? '' : 'none';
    document.getElementById('amount-row').style.display = show ? '' : 'none';
}
document.addEventListener('DOMContentLoaded', () => toggleRefund(document.getElementById('end_mode').value));
</script>
@endsection
