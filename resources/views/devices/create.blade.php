@extends('layouts.app')

@section('title', 'Přidat zařízení')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('devices.index') }}">Zařízení</a> &raquo;
        Přidat zařízení
    </div>
@endsection

@section('content')
    <h2>Přidat zařízení</h2>

    <form method="POST" action="{{ route('devices.store') }}" class="form">
        @csrf

        <table class="extended" cellspacing="0">
            <tbody>
                <tr>
                    <th><label for="user_id">Uživatel <span class="required">*</span></label></th>
                    <td>
                        @if($preselectedUserId)
                            <input type="hidden" name="user_id" value="{{ $preselectedUserId }}">
                            {{ $users->firstWhere('id', $preselectedUserId)?->full_name ?? $preselectedUserId }}
                        @else
                            <select id="user_id" name="user_id">
                                <option value="">— vyberte uživatele —</option>
                                @foreach($users as $user)
                                    <option value="{{ $user->id }}" @selected(old('user_id') == $user->id)>
                                        {{ $user->full_name }} ({{ $user->login }})
                                    </option>
                                @endforeach
                            </select>
                        @endif
                        @error('user_id') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="name">Název <span class="required">*</span></label></th>
                    <td>
                        <input type="text" id="name" name="name" value="{{ old('name') }}" maxlength="255">
                        @error('name') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="type">Typ <span class="required">*</span></label></th>
                    <td>
                        <select id="type" name="type">
                            <option value="">— vyberte typ —</option>
                            @foreach($deviceTypes as $id => $label)
                                <option value="{{ $id }}" @selected(old('type') == $id)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('type') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="trade_name">Obchodní název</label></th>
                    <td>
                        <input type="text" id="trade_name" name="trade_name" value="{{ old('trade_name') }}" maxlength="50">
                        @error('trade_name') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="login">Login</label></th>
                    <td>
                        <input type="text" id="login" name="login" value="{{ old('login') }}" maxlength="30">
                        @error('login') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="password">Heslo</label></th>
                    <td>
                        <input type="text" id="password" name="password" value="{{ old('password') }}" maxlength="30">
                        @error('password') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="price">Cena</label></th>
                    <td>
                        <input type="number" step="0.01" id="price" name="price" value="{{ old('price') }}">
                        @error('price') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="payment_rate">Měsíční splátka</label></th>
                    <td>
                        <input type="number" step="0.01" id="payment_rate" name="payment_rate" value="{{ old('payment_rate') }}">
                        @error('payment_rate') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="buy_date">Datum koupě</label></th>
                    <td>
                        <input type="date" id="buy_date" name="buy_date" value="{{ old('buy_date') }}">
                        @error('buy_date') <span class="error">{{ $message }}</span> @enderror
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
