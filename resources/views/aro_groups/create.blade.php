@extends('layouts.app')
@section('title', 'Přidat skupinu')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('aro-groups.index') }}">Přístupová práva</a> » Přidat skupinu
    </div>
@endsection
@section('content')
    <h2>Přidat skupinu přístupových práv</h2>

    <form method="POST" action="{{ route('aro-groups.store') }}">
        @csrf
        <table class="extended" cellspacing="0">
            <tr>
                <th>Název skupiny</th>
                <td>
                    <input type="text" name="name" value="{{ old('name') }}" style="width:250px">
                    @error('name') <span style="color:red">{{ $message }}</span> @enderror
                </td>
            </tr>
            <tr>
                <th>Nadřazená skupina</th>
                <td>
                    <select name="parent_id">
                        @foreach($groups as $g)
                            <option value="{{ $g->id }}" {{ old('parent_id') == $g->id ? 'selected' : '' }}>
                                {{ $g->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('parent_id') <span style="color:red">{{ $message }}</span> @enderror
                </td>
            </tr>
        </table>
        <div style="margin-top:1em;">
            <button type="submit">Uložit</button>
            <a href="{{ route('aro-groups.index') }}" style="margin-left:1em;">Zrušit</a>
        </div>
    </form>
@endsection
