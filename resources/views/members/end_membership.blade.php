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
            <div class="m-form-hint" id="refund-deduct-hint"
                 style="display:none;color:#b87333;background:#fff8e1;border-left:3px solid #e8651a;padding:6px 10px;margin-top:6px"></div>
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
const RF_BALANCE       = {{ (float) $balance }};
const RF_MONTHLY_FEE   = {{ (float) ($monthlyFee ?? 0) }};
const RF_DEDUCT_DAY    = {{ (int) ($deductDay ?? 1) }};
const RF_TODAY         = '{{ $today }}';
const RF_TODAY_DEDUCTED= {{ ($todayDeducted ?? false) ? 'true' : 'false' }};

function rfParseDate(s) {
    if (!s) return null;
    const [y,m,d] = s.split('-').map(Number);
    if (!y || !m || !d) return null;
    return new Date(y, m-1, d);
}
function rfFmtCzk(v) {
    return new Intl.NumberFormat('cs-CZ', {minimumFractionDigits: 0, maximumFractionDigits: 2}).format(v);
}

// Spočítej kolik srážek tarifu cron stihne před vystoupením.
// Cron strhne v deduct_day pokud (m.leaving_date > deduct_date), takže počítáme
// všechny deduct_days D kde today ≤ D < leaving_date a vyřazujeme dnešní pokud
// už proběhl.
function rfFutureDeductCount(leavingStr) {
    const leaving = rfParseDate(leavingStr);
    const today   = rfParseDate(RF_TODAY);
    if (!leaving || !today || leaving <= today) return 0;
    let count = 0;
    // Iteruj měsíc po měsíci od dnešního měsíce do měsíce vystoupení
    let cursor = new Date(today.getFullYear(), today.getMonth(), 1);
    const end  = new Date(leaving.getFullYear(), leaving.getMonth(), 1);
    while (cursor <= end) {
        const lastDay = new Date(cursor.getFullYear(), cursor.getMonth()+1, 0).getDate();
        const effDay  = Math.min(RF_DEDUCT_DAY, lastDay);
        const deductDate = new Date(cursor.getFullYear(), cursor.getMonth(), effDay);
        if (deductDate >= today && deductDate < leaving) {
            // dnes? skip pokud už proběhl
            const isToday = deductDate.getTime() === today.getTime();
            if (!(isToday && RF_TODAY_DEDUCTED)) count++;
        }
        cursor = new Date(cursor.getFullYear(), cursor.getMonth()+1, 1);
    }
    return count;
}

function rfRecompute() {
    const leavingStr = document.getElementById('leaving_date').value;
    const hint       = document.getElementById('refund-deduct-hint');
    const input      = document.getElementById('refund_amount');
    if (!input) return;

    const count = rfFutureDeductCount(leavingStr);
    if (count <= 0 || RF_MONTHLY_FEE <= 0) {
        hint.style.display = 'none';
        // Bez budoucí srážky zpět na plný zůstatek (jen pokud uživatel ručně neupravil)
        if (input.dataset.autofilled !== '0') {
            input.value = RF_BALANCE.toFixed(2);
            input.dataset.autofilled = '1';
        }
        return;
    }

    const reduction = count * RF_MONTHLY_FEE;
    const refund    = Math.max(0, RF_BALANCE - reduction);

    if (input.dataset.autofilled !== '0') {
        input.value = refund.toFixed(2);
        input.dataset.autofilled = '1';
    }
    hint.innerHTML =
        '⚠️ Datum vystoupení překračuje ' + count + '× automatickou srážku tarifu. ' +
        'Z vratky odečteno <strong>' + rfFmtCzk(reduction) + ' Kč</strong> ' +
        '(' + count + ' × ' + rfFmtCzk(RF_MONTHLY_FEE) + ' Kč) — ' +
        'cron stihne strhnout tarif před datem vystoupení a vy byste jinak vrátili ' +
        'více než kolik na účtu reálně zbude. ' +
        'Vratka: ' + rfFmtCzk(RF_BALANCE) + ' − ' + rfFmtCzk(reduction) + ' = <strong>' +
        rfFmtCzk(refund) + ' Kč</strong>.';
    hint.style.display = '';
}

function toggleRefund(mode) {
    const show = mode === '3';
    document.getElementById('refund-row').style.display = show ? '' : 'none';
    if (show) rfRecompute();
}

document.addEventListener('DOMContentLoaded', () => {
    toggleRefund(document.getElementById('end_mode').value);
    document.getElementById('leaving_date').addEventListener('change', rfRecompute);
    const ref = document.getElementById('refund_amount');
    if (ref) ref.addEventListener('input', () => { ref.dataset.autofilled = '0'; });
});
</script>
</div>
@endsection
