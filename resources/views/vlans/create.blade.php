@extends('layouts.app')

@section('title', 'Přidat VLAN')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('vlans.index') }}">VLANy</a> &raquo;
        Přidat VLAN
    </div>
@endsection

@section('content')
    <h2>Přidat VLAN</h2>

    <form method="POST" action="{{ route('vlans.store') }}" class="form">
        @csrf

        <table class="extended" cellspacing="0">
            <tbody>
                <tr>
                    <th><label for="tag_802_1q">802.1Q tag <span class="required">*</span></label></th>
                    <td>
                        <input type="number" id="tag_802_1q" name="tag_802_1q"
                               value="{{ old('tag_802_1q') }}" min="1" max="4094">
                        @error('tag_802_1q') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="name">Název</label></th>
                    <td>
                        <input type="text" id="name" name="name"
                               value="{{ old('name') }}" maxlength="254">
                        @error('name') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="comment">Komentář</label></th>
                    <td>
                        <textarea id="comment" name="comment" maxlength="254">{{ old('comment') }}</textarea>
                        @error('comment') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
            </tbody>
        </table>

        <p><input type="submit" value="Přidat"></p>
    </form>
@endsection
