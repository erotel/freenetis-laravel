@extends('layouts.app')
@section('title', 'Automatické stahování — ' . $account->name)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('bank_accounts.index') }}">Bankovní účty</a> &raquo;
    <a href="{{ route('bank_accounts.show', $account->id) }}">{{ $account->name }}</a> &raquo;
    Automatické stahování výpisů
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Automatické stahování výpisů — {{ $account->name }}</h2></div>

<div class="m-actions">
    <a class="m-btn" href="{{ route('bank_accounts.show', $account->id) }}">&laquo; Zpět na účet</a>
</div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

<div class="m-section">Aktuální pravidla</div>
@if($rules->isNotEmpty())
<div class="m-card" style="padding:0;overflow-x:auto;margin-bottom:16px">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px">ID</th>
            <th style="width:130px">Typ</th>
            <th>Atributy</th>
            <th style="width:70px">E-mail</th>
            <th style="width:50px">SMS</th>
            <th style="width:70px">Akce</th>
        </tr>
    </thead>
    <tbody>
        @foreach($rules as $rule)
        <tr>
            <td>{{ $rule->id }}</td>
            <td style="font-size:14px">{{ $rule->typeLabel() }}</td>
            <td style="font-size:14px">{{ $rule->attributeLabel() }}</td>
            <td>@if($rule->email_enabled)<span class="m-tag m-tag-green">Ano</span>@else —@endif</td>
            <td>@if($rule->sms_enabled)<span class="m-tag m-tag-green">Ano</span>@else —@endif</td>
            <td>
                @if($canDelete)
                <form method="POST" action="{{ route('bank_accounts.auto_downloads.destroy', $rule->id) }}" style="display:inline">
                    @csrf @method('DELETE')
                    <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;font-size:14px;color:#c0392b"
                            onclick="return confirm('Smazat pravidlo?')">Smazat</button>
                </form>
                @endif
            </td>
        </tr>
        @endforeach
    </tbody>
</table>
</div>
@else
<div class="m-alert m-alert-info" style="margin-bottom:16px">Žádná pravidla.</div>
@endif

@if($canAdd)
<div class="m-section">Přidat pravidlo</div>
<form method="POST" action="{{ route('bank_accounts.auto_downloads.store', $account->id) }}" id="autoDownloadForm">
@csrf
<div class="m-card" style="margin-bottom:16px;max-width:480px">
    <div class="m-form-group">
        <label class="m-form-label">Typ <span style="color:#c0392b">*</span></label>
        <select class="m-form-select" name="type" id="typeSelect" onchange="updateAttributeFields()">
            @foreach($typeLabels as $val => $label)
                <option value="{{ $val }}" @selected(old('type') == $val)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    @for($i = 0; $i < 2; $i++)
    <div class="m-form-group" id="attrRow{{ $i }}" style="display:none">
        <label class="m-form-label" id="attrLabel{{ $i }}" for="attribute_{{ $i }}">Atribut {{ $i + 1 }}</label>
        <input class="m-form-input" type="number" name="attribute[{{ $i }}]" id="attribute_{{ $i }}"
               value="{{ old('attribute.' . $i) }}" min="0" max="31" style="max-width:100px">
    </div>
    @endfor
    <div style="display:flex;gap:20px;flex-wrap:wrap;margin-top:4px">
        <label style="display:flex;align-items:center;gap:6px;font-size:16px;cursor:pointer">
            <input type="checkbox" name="email_enabled" id="email_enabled" value="1" @checked(old('email_enabled'))>
            E-mail
        </label>
        <label style="display:flex;align-items:center;gap:6px;font-size:16px;cursor:pointer">
            <input type="checkbox" name="sms_enabled" id="sms_enabled" value="1" @checked(old('sms_enabled'))>
            SMS
        </label>
    </div>
</div>
<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Přidat</button>
    <a class="m-btn" href="{{ route('bank_accounts.show', $account->id) }}">Zrušit</a>
</div>
</form>

<script>
var typeAttrs = @json($typeAttrs);
function updateAttributeFields() {
    var type = parseInt(document.getElementById('typeSelect').value);
    var attrs = typeAttrs[type] || [];
    for (var i = 0; i < 2; i++) {
        var row   = document.getElementById('attrRow' + i);
        var label = document.getElementById('attrLabel' + i);
        var input = document.getElementById('attribute_' + i);
        if (i < attrs.length) {
            row.style.display = '';
            label.textContent = attrs[i].name + ' *';
            input.min = attrs[i].min;
            input.max = attrs[i].max;
            input.required = true;
        } else {
            row.style.display = 'none';
            input.required = false;
        }
    }
}
document.addEventListener('DOMContentLoaded', updateAttributeFields);
</script>
@endif

</div>
@endsection
