@extends('layouts.app')
@section('title', 'Upravit typ')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
    <div id="breadcrumbs"><a href="{{ route('enum-types.index') }}">Typy</a> &raquo; Upravit typ</div>
@endsection
@section('content')
    <h2>Upravit typ: {{ $enumType->value }}</h2>
    <form method="POST" action="{{ route('enum-types.update', $enumType->id) }}">
        @csrf @method('PUT')
        <table class="extended" cellspacing="0">
            <tr>
                <th>Skupina</th>
                <td>
                    <select name="type_id">
                        @foreach($typeNames as $tn)
                            <option value="{{ $tn->id }}" {{ old('type_id', $enumType->type_id) == $tn->id ? 'selected' : '' }}>
                                {{ $tn->type_name }}
                            </option>
                        @endforeach
                    </select>
                </td>
            </tr>
            <tr>
                <th>Název</th>
                <td>
                    <input type="text" name="value" value="{{ old('value', $enumType->value) }}" style="width:250px">
                    @error('value') <span style="color:red">{{ $message }}</span> @enderror
                </td>
            </tr>
        </table>
        <div style="margin-top:1em;">
            <button type="submit">Uložit</button>
            <a href="{{ route('enum-types.index') }}" style="margin-left:1em;">Zrušit</a>
        </div>
    </form>
@endsection
