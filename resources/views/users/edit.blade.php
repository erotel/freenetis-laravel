@extends('layouts.app')

@section('title', 'Upravit uživatele: ' . $user->full_name)

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('users.index') }}">Uživatelé</a> &raquo;
        <a href="{{ route('users.show', $user->id) }}">{{ $user->full_name }}</a> &raquo;
        Upravit
    </div>
@endsection

@section('content')
    <h2>Upravit uživatele: {{ $user->full_name }}</h2>

    <form method="POST" action="{{ route('users.update', $user->id) }}" class="form">
        @csrf
        @method('PUT')

        <table class="extended" cellspacing="0">
            <thead>
                <tr><th colspan="2">Základní informace</th></tr>
            </thead>
            <tbody>
                @if($canEditMember)
                    <tr>
                        <th><label for="member_id">Člen <span class="required">*</span></label></th>
                        <td>
                            <select id="member_id" name="member_id">
                                @foreach($members as $member)
                                    <option value="{{ $member->id }}" @selected(old('member_id', $user->member_id) == $member->id)>
                                        {{ $member->name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('member_id') <span class="error">{{ $message }}</span> @enderror
                        </td>
                    </tr>
                @endif
                @if($canEditLogin)
                    <tr>
                        <th><label for="login">Login <span class="required">*</span></label></th>
                        <td>
                            <input type="text" id="login" name="login" value="{{ old('login', $user->login) }}" maxlength="100">
                            @error('login') <span class="error">{{ $message }}</span> @enderror
                        </td>
                    </tr>
                @else
                    <tr>
                        <th>Login</th>
                        <td>{{ $user->login }}</td>
                    </tr>
                @endif
                <tr>
                    <th><label for="pre_title">Titul před</label></th>
                    <td>
                        <input type="text" id="pre_title" name="pre_title" value="{{ old('pre_title', $user->pre_title) }}" maxlength="50">
                        @error('pre_title') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="name">Jméno</label></th>
                    <td>
                        <input type="text" id="name" name="name" value="{{ old('name', $user->name) }}" maxlength="100">
                        @error('name') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="middle_name">Druhé jméno</label></th>
                    <td>
                        <input type="text" id="middle_name" name="middle_name" value="{{ old('middle_name', $user->middle_name) }}" maxlength="100">
                        @error('middle_name') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="surname">Příjmení <span class="required">*</span></label></th>
                    <td>
                        <input type="text" id="surname" name="surname" value="{{ old('surname', $user->surname) }}" maxlength="100">
                        @error('surname') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="post_title">Titul za</label></th>
                    <td>
                        <input type="text" id="post_title" name="post_title" value="{{ old('post_title', $user->post_title) }}" maxlength="50">
                        @error('post_title') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="birthday">Datum narození</label></th>
                    <td>
                        <input type="date" id="birthday" name="birthday" value="{{ old('birthday', $user->birthday) }}">
                        @error('birthday') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="type">Typ <span class="required">*</span></label></th>
                    <td>
                        <select id="type" name="type">
                            <option value="1" @selected(old('type', $user->type) == 1)>Hlavní uživatel</option>
                            <option value="2" @selected(old('type', $user->type) == 2)>Uživatel</option>
                        </select>
                        @error('type') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                @if($canEditComment)
                    <tr>
                        <th><label for="comment">Komentář</label></th>
                        <td>
                            <textarea id="comment" name="comment" maxlength="250">{{ old('comment', $user->comment) }}</textarea>
                            @error('comment') <span class="error">{{ $message }}</span> @enderror
                        </td>
                    </tr>
                @endif
            </tbody>
        </table>

        <p><input type="submit" value="Uložit"></p>
    </form>
@endsection
