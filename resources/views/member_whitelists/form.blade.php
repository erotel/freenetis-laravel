@extends('layouts.app')
@section('title', $action === 'create' ? 'Přidat whitelist' : 'Upravit whitelist')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('members.index') }}">Členové</a> &raquo;
    <a href="{{ route('members.show', $member->id) }}">{{ $member->name }}</a> &raquo;
    {{ $action === 'create' ? 'Přidat whitelist' : 'Upravit whitelist' }}
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row">
    <h2>{{ $action === 'create' ? 'Přidat whitelist záznam' : 'Upravit whitelist záznam' }}</h2>
</div>
<div class="m-subtitle">Člen: {{ $member->name }}</div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

@if($action === 'create')
<form method="POST" action="{{ route('member_whitelists.store', $member->id) }}">
@else
<form method="POST" action="{{ route('member_whitelists.update', $whitelist->id) }}">
@method('PUT')
@endif
@csrf

<div class="m-card" style="margin-bottom:16px;max-width:480px">
    <div class="m-form-group">
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
            <input type="checkbox" name="permanent" id="permanent" value="1"
                   @checked(old('permanent', $whitelist?->permanent))
                   onchange="toggleUntil()">
            Permanentní
        </label>
        <div class="m-form-hint">Při zaškrtnutí bude platnost nastavena na neomezenou.</div>
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="since">Od <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="date" name="since" id="since"
                   value="{{ old('since', $whitelist?->since ?? now()->toDateString()) }}">
            @error('since') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group" id="untilRow">
            <label class="m-form-label" for="until">Do <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="date" name="until" id="until"
                   value="{{ old('until', ($whitelist && $whitelist->until !== '9999-12-31') ? $whitelist->until : '') }}">
            @error('until') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="m-form-group">
        <label class="m-form-label" for="comment">Komentář</label>
        <textarea class="m-form-input" name="comment" id="comment" rows="3">{{ old('comment', $whitelist?->comment) }}</textarea>
        @error('comment') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
</div>

<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Uložit</button>
    <a class="m-btn" href="{{ route('members.show', $member->id) }}">Zrušit</a>
</div>
</form>

<script>
function toggleUntil() {
    var perm  = document.getElementById('permanent').checked;
    var row   = document.getElementById('untilRow');
    var input = document.getElementById('until');
    row.style.display = perm ? 'none' : '';
    input.required    = !perm;
    if (perm) input.value = '';
}
document.addEventListener('DOMContentLoaded', toggleUntil);
</script>
</div>
@endsection
