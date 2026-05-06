@extends('layouts.app')
@section('title', $acl ? 'Upravit ACL pravidlo' : 'Nové ACL pravidlo')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('aro-groups.index') }}">Přístupová práva</a> &raquo;
    {{ $acl ? 'Upravit ACL pravidlo č. ' . $acl->id : 'Nové ACL pravidlo' }}
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>{{ $acl ? 'Upravit ACL pravidlo č. ' . $acl->id : 'Nové ACL pravidlo' }}</h2></div>

@if($errors->any())
<div class="m-alert m-alert-danger">
    <ul style="margin:0;padding-left:1.2em">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

<form method="POST" action="{{ $acl ? route('acl.update', $acl->id) : route('acl.store') }}">
@csrf
@if($acl) @method('PUT') @endif

<div class="m-card" style="margin-bottom:16px;max-width:600px">
    <div class="m-form-group">
        <label class="m-form-label">Popis (note)</label>
        <input class="m-form-input" type="text" name="note" value="{{ old('note', $acl?->note) }}">
        @error('note') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    <div class="m-form-group">
        <label class="m-form-label">Akce</label>
        <div style="display:flex;flex-wrap:wrap;gap:12px">
            @foreach($actions as $action)
            <label style="display:flex;align-items:center;gap:6px;font-size:16px;cursor:pointer">
                <input type="checkbox" name="actions[]" value="{{ $action }}"
                    {{ in_array($action, old('actions', $selected['actions'] ?? [])) ? 'checked' : '' }}>
                {{ $action }}
            </label>
            @endforeach
        </div>
        @error('actions') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    <div class="m-form-group">
        <label class="m-form-label">Skupiny</label>
        <div style="display:flex;flex-wrap:wrap;gap:6px 16px">
            @foreach($groups as $group)
            <label style="display:flex;align-items:center;gap:6px;font-size:16px;cursor:pointer">
                <input type="checkbox" name="group_ids[]" value="{{ $group->id }}"
                    {{ in_array($group->id, old('group_ids', $selected['group_ids'] ?? [])) ? 'checked' : '' }}>
                {{ $group->name }}
            </label>
            @endforeach
        </div>
    </div>
    <div class="m-form-group">
        <label class="m-form-label">Objekty (resources)</label>
        @error('axo_ids') <div class="m-form-hint" style="color:#c0392b;margin-bottom:6px">{{ $message }}</div> @enderror
        @php $currentSection = null; @endphp
        @foreach($axo as $item)
            @if($item->section_value !== $currentSection)
                @if($currentSection !== null) </div> @endif
                <div style="margin-top:10px;font-weight:600;font-size:14px;color:#888;text-transform:uppercase;letter-spacing:.05em">{{ $item->section_value }}</div>
                <div style="padding-left:12px;display:flex;flex-wrap:wrap;gap:4px 16px">
                @php $currentSection = $item->section_value; @endphp
            @endif
            <label style="display:flex;align-items:center;gap:4px;font-size:14px;cursor:pointer">
                <input type="checkbox" name="axo_ids[]" value="{{ $item->id }}"
                    {{ in_array($item->id, old('axo_ids', $selected['axo_ids'] ?? [])) ? 'checked' : '' }}>
                {{ $item->name }} <span style="color:#aaa">({{ $item->value }})</span>
            </label>
        @endforeach
        @if($currentSection !== null) </div> @endif
    </div>
</div>

<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Uložit</button>
    <a class="m-btn" href="{{ route('aro-groups.index') }}">Zrušit</a>
</div>
</form>
</div>
@endsection
