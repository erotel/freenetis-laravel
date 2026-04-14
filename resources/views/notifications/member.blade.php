@extends('layouts.app')

@section('title', 'Oznámení člena — ' . $member->name)

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('members.index') }}">Členové</a> &raquo;
        <a href="{{ route('members.show', $member->id) }}">{{ $member->name }}</a> &raquo;
        Oznámení
    </div>
@endsection

@section('content')
    <h2>Nastavení oznámení člena {{ $member->name }}</h2>

    @if($errors->any())
        <div class="message_error">
            @foreach($errors->all() as $err)
                <p>{{ $err }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('notifications.member.notify', $member->id) }}" class="form">
        @csrf

        <table class="extended" cellspacing="0">
            <tbody>
                <tr>
                    <th><label for="message_id">Zpráva <span class="required">*</span></label></th>
                    <td>
                        <select id="message_id" name="message_id">
                            <option value="">— Vyberte zprávu —</option>
                            @foreach($messages as $msgId => $msgName)
                                <option value="{{ $msgId }}" @selected(old('message_id') == $msgId)>
                                    {{ $msgName }}
                                </option>
                            @endforeach
                        </select>
                        @error('message_id') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="comment">Komentář</label></th>
                    <td>
                        <textarea id="comment" name="comment" rows="3" cols="50">{{ old('comment') }}</textarea>
                    </td>
                </tr>
                <tr>
                    <th><label for="redirection">Přesměrování</label></th>
                    <td>
                        <select id="redirection" name="redirection">
                            <option value="{{ $ACTIVATE }}" @selected(old('redirection', $ACTIVATE) == $ACTIVATE)>Aktivovat</option>
                            <option value="{{ $KEEP }}"     @selected(old('redirection') == $KEEP)>Ponechat</option>
                            <option value="{{ $DEACTIVATE }}" @selected(old('redirection') == $DEACTIVATE)>Deaktivovat</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="email">E-mail</label></th>
                    <td>
                        <select id="email" name="email">
                            <option value="{{ $ACTIVATE }}" @selected(old('email', $ACTIVATE) == $ACTIVATE)>Odeslat</option>
                            <option value="{{ $KEEP }}"     @selected(old('email') == $KEEP)>Neposílat</option>
                            <option value="{{ $DEACTIVATE }}" @selected(old('email') == $DEACTIVATE)>Deaktivovat</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="sms">SMS</label></th>
                    <td>
                        <select id="sms" name="sms">
                            <option value="{{ $ACTIVATE }}" @selected(old('sms', $ACTIVATE) == $ACTIVATE)>Odeslat</option>
                            <option value="{{ $KEEP }}"     @selected(old('sms') == $KEEP)>Neposílat</option>
                            <option value="{{ $DEACTIVATE }}" @selected(old('sms') == $DEACTIVATE)>Deaktivovat</option>
                        </select>
                    </td>
                </tr>
            </tbody>
        </table>

        <p>
            <button type="submit">Aktivovat</button>
            <a href="{{ route('members.show', $member->id) }}">Zrušit</a>
        </p>
    </form>
@endsection
