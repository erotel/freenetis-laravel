@extends('layouts.app')
@section('title', $acl ? 'Upravit ACL pravidlo' : 'Nové ACL pravidlo')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('aro-groups.index') }}">Přístupová práva</a> »
        {{ $acl ? 'Upravit ACL pravidlo č. ' . $acl->id : 'Nové ACL pravidlo' }}
    </div>
@endsection
@section('content')
    <h2>{{ $acl ? 'Upravit ACL pravidlo č. ' . $acl->id : 'Nové ACL pravidlo' }}</h2>

    <form method="POST" action="{{ $acl ? route('acl.update', $acl->id) : route('acl.store') }}">
        @csrf
        @if($acl) @method('PUT') @endif

        <table class="extended" cellspacing="0">
            <tr>
                <th>Popis (note)</th>
                <td>
                    <input type="text" name="note" value="{{ old('note', $acl?->note) }}" style="width:400px">
                    @error('note') <span style="color:red">{{ $message }}</span> @enderror
                </td>
            </tr>
            <tr>
                <th>Akce</th>
                <td>
                    @foreach($actions as $action)
                        <label style="display:inline-block; margin-right:12px;">
                            <input type="checkbox" name="actions[]" value="{{ $action }}"
                                {{ in_array($action, old('actions', $selected['actions'] ?? [])) ? 'checked' : '' }}>
                            {{ $action }}
                        </label>
                    @endforeach
                    @error('actions') <span style="color:red">{{ $message }}</span> @enderror
                </td>
            </tr>
            <tr>
                <th style="vertical-align:top; padding-top:6px;">Skupiny</th>
                <td>
                    @foreach($groups as $group)
                        <label style="display:block;">
                            <input type="checkbox" name="group_ids[]" value="{{ $group->id }}"
                                {{ in_array($group->id, old('group_ids', $selected['group_ids'] ?? [])) ? 'checked' : '' }}>
                            {{ $group->name }}
                        </label>
                    @endforeach
                </td>
            </tr>
            <tr>
                <th style="vertical-align:top; padding-top:6px;">Objekty (resources)</th>
                <td>
                    @error('axo_ids') <span style="color:red">{{ $message }}</span> @enderror
                    @php $currentSection = null; @endphp
                    @foreach($axo as $item)
                        @if($item->section_value !== $currentSection)
                            @if($currentSection !== null) </div> @endif
                            <div style="margin-top:8px; font-weight:bold; color:#333;">{{ $item->section_value }}</div>
                            <div style="padding-left:12px;">
                            @php $currentSection = $item->section_value; @endphp
                        @endif
                        <label style="display:block; font-size:0.9em;">
                            <input type="checkbox" name="axo_ids[]" value="{{ $item->id }}"
                                {{ in_array($item->id, old('axo_ids', $selected['axo_ids'] ?? [])) ? 'checked' : '' }}>
                            {{ $item->name }} <small style="color:#888;">({{ $item->value }})</small>
                        </label>
                    @endforeach
                    @if($currentSection !== null) </div> @endif
                </td>
            </tr>
        </table>

        <div style="margin-top:1em;">
            <button type="submit">Uložit</button>
            <a href="{{ route('aro-groups.index') }}" style="margin-left:1em;">Zrušit</a>
        </div>
    </form>
@endsection
