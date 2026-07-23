@extends('layouts.app')
@section('title', 'Přidat zařízení')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('devices.index') }}">Zařízení</a> &raquo; Přidat zařízení
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Přidat zařízení</h2></div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

<form method="POST" action="{{ route('devices.store') }}">
@csrf
<div class="m-card" style="margin-bottom:16px;max-width:560px">
    <div class="m-form-group">
        <label class="m-form-label" for="member_id">Člen <span style="color:#c0392b">*</span></label>
        @if($preselectedMemberId)
            <input type="hidden" name="member_id" value="{{ $preselectedMemberId }}">
            <div class="m-form-input" style="background:var(--fn-quote-bg);color:var(--fn-text);cursor:default">{{ $members->firstWhere('id', $preselectedMemberId)?->display_name ?? $preselectedMemberId }}</div>
        @else
            <div class="m-form-row" style="align-items:flex-end;gap:8px;margin-bottom:8px">
                <div class="m-form-group" style="flex:0 0 200px;margin-bottom:0">
                    <label class="m-form-label" for="member_id_lookup" style="font-weight:400;color:var(--fn-muted)">Najít podle ID člena</label>
                    <input class="m-form-input" type="number" id="member_id_lookup" min="1" placeholder="ID člena" autocomplete="off">
                </div>
                <button type="button" class="m-btn" id="member_id_lookup_btn">Najít</button>
                <div id="member_id_lookup_msg" class="m-form-hint" style="margin-bottom:6px"></div>
            </div>
            <select class="m-form-select" id="member_id" name="member_id">
                <option value="">— vyberte člena —</option>
                @foreach($members as $m)
                    <option value="{{ $m->id }}" @selected(old('member_id') == $m->id)>
                        {{ $m->display_name }} ({{ $m->login }})
                    </option>
                @endforeach
            </select>
        @endif
        @error('member_id') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    <div class="m-form-group">
        <label class="m-form-label" for="address_display">Adresa umístění</label>
        <input type="hidden" id="address_point_id" name="address_point_id" value="{{ old('address_point_id') }}">
        <input class="m-form-input" type="text" id="address_display" readonly
               style="background:var(--fn-quote-bg);cursor:default" placeholder="— podle člena —"
               value="{{ old('address_point_id') ? ($members->firstWhere('id', old('member_id') ?: $preselectedMemberId)?->address_label ?? '') : '' }}">
        <div class="m-form-hint">Předvyplní se automaticky podle vybraného člena.</div>
        @error('address_point_id') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="name">Název <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="text" id="name" name="name" value="{{ old('name') }}" maxlength="255">
            @error('name') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="type">Typ <span style="color:#c0392b">*</span></label>
            <select class="m-form-select" id="type" name="type">
                <option value="">— vyberte typ —</option>
                @foreach($deviceTypes as $id => $label)
                    <option value="{{ $id }}" @selected(old('type') == $id)>{{ $label }}</option>
                @endforeach
            </select>
            @error('type') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="trade_name">Obchodní název</label>
            <input class="m-form-input" type="text" id="trade_name" name="trade_name" value="{{ old('trade_name') }}" maxlength="50">
            @error('trade_name') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="buy_date">Datum koupě</label>
            <input class="m-form-input" type="date" id="buy_date" name="buy_date" value="{{ old('buy_date', date('Y-m-d')) }}">
            @error('buy_date') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="login">Login</label>
            <input class="m-form-input" type="text" id="login" name="login" value="{{ old('login') }}" maxlength="30">
            @error('login') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="password">Heslo</label>
            <input class="m-form-input" type="text" id="password" name="password" value="{{ old('password') }}" maxlength="30">
            @error('password') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="price">Cena</label>
            <input class="m-form-input" type="number" step="0.01" id="price" name="price" value="{{ old('price') }}">
            @error('price') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="payment_rate">Měsíční splátka</label>
            <input class="m-form-input" type="number" step="0.01" id="payment_rate" name="payment_rate" value="{{ old('payment_rate') }}">
            @error('payment_rate') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="m-form-group">
        <label class="m-form-label" for="comment">Komentář</label>
        <textarea class="m-form-input" id="comment" name="comment" rows="3" maxlength="254">{{ old('comment') }}</textarea>
        @error('comment') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
</div>
<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Přidat</button>
    <a class="m-btn" href="{{ route('devices.index') }}">Zrušit</a>
</div>
</form>
</div>

<script>
(function () {
    // Mapa člen → adresa umístění (address_point). Auto-vyplnění pole podle člena.
    var memberAddr = @json($members->mapWithKeys(fn($m) => [$m->id => ['ap' => $m->address_point_id, 'label' => $m->address_label]]));
    var apHidden = document.getElementById('address_point_id');
    var apDisplay = document.getElementById('address_display');
    var userEdited = (apHidden && apHidden.value !== ''); // po chybě validace nepřepisovat

    function applyAddress(memberId) {
        if (!apHidden || !apDisplay) return;
        var a = memberAddr[memberId];
        if (a && a.ap) {
            apHidden.value = a.ap;
            apDisplay.value = a.label || '';
        } else {
            apHidden.value = '';
            apDisplay.value = '';
            apDisplay.placeholder = a ? '— člen nemá adresu —' : '— podle člena —';
        }
    }

    var select = document.getElementById('member_id');
    @if($preselectedMemberId)
        if (!userEdited) applyAddress({{ (int) $preselectedMemberId }});
    @else
        if (select) {
            if (!userEdited && select.value) applyAddress(select.value);
            select.addEventListener('change', function () { applyAddress(this.value); });
        }
    @endif

    // Vyhledání člena podle ID
    var input  = document.getElementById('member_id_lookup');
    var btn    = document.getElementById('member_id_lookup_btn');
    var msg    = document.getElementById('member_id_lookup_msg');
    if (input && btn && select) {
        function lookup() {
            var id = (input.value || '').trim();
            if (id === '') { msg.textContent = ''; return; }
            var opt = select.querySelector('option[value="' + id + '"]');
            if (opt) {
                select.value = id;
                applyAddress(id);
                select.focus();
                msg.style.color = 'var(--fn-ok, #15803d)';
                msg.textContent = 'Vybráno: ' + opt.textContent.trim();
            } else {
                msg.style.color = '#c0392b';
                msg.textContent = 'Člen s ID ' + id + ' není v seznamu (nemá hlavního uživatele).';
            }
        }
        btn.addEventListener('click', lookup);
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); lookup(); }
        });
    }
})();
</script>
@endsection
