@extends('layouts.app')
@section('title', 'Přidat typ')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
    <div id="breadcrumbs"><a href="{{ route('enum-types.index') }}">Typy</a> &raquo; Přidat typ</div>
@endsection
@section('content')
    <h2>Přidat typ</h2>
    <form method="POST" action="{{ route('enum-types.store') }}">
        @csrf
        <table class="extended" cellspacing="0">
            <tr>
                <th>Skupina</th>
                <td>
                    <select name="type_id">
                        @foreach($typeNames as $tn)
                            <option value="{{ $tn->id }}" {{ old('type_id') == $tn->id ? 'selected' : '' }}>
                                {{ $tn->type_name }}
                            </option>
                        @endforeach
                    </select>
                    @error('type_id') <span style="color:red">{{ $message }}</span> @enderror
                </td>
            </tr>
            <tr>
                <th>Název</th>
                <td>
                    <input type="text" name="value" value="{{ old('value') }}" style="width:250px">
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
