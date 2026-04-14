@extends('layouts.app')

@section('title', $action === 'create' ? 'Přidat whitelist' : 'Upravit whitelist')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('members.index') }}">Členové</a> &raquo;
        <a href="{{ route('members.show', $member->id) }}">{{ $member->name }}</a> &raquo;
        {{ $action === 'create' ? 'Přidat whitelist' : 'Upravit whitelist' }}
    </div>
@endsection

@section('content')
    <h2>{{ $action === 'create' ? 'Přidat whitelist záznam' : 'Upravit whitelist záznam' }}</h2>
    <p><strong>Člen:</strong> {{ $member->name }}</p>

    @if($errors->any())
        <div class="message_error">
            @foreach($errors->all() as $err)
                <p>{{ $err }}</p>
            @endforeach
        </div>
    @endif

    @if($action === 'create')
        <form method="POST" action="{{ route('member_whitelists.store', $member->id) }}" class="form">
    @else
        <form method="POST" action="{{ route('member_whitelists.update', $whitelist->id) }}" class="form">
        @method('PUT')
    @endif
    @csrf

    <table class="extended" cellspacing="0">
        <tbody>
            <tr>
                <th><label for="permanent">Permanentní</label></th>
                <td>
                    <input type="checkbox" name="permanent" id="permanent" value="1"
                           @checked(old('permanent', $whitelist?->permanent))
                           onchange="toggleUntil()">
                    <small>Při zaškrtnutí bude platnost nastavena na 9999-12-31</small>
                </td>
            </tr>
            <tr>
                <th><label for="since">Od <span class="required">*</span></label></th>
                <td>
                    <input type="date" name="since" id="since"
                           value="{{ old('since', $whitelist?->since ?? now()->toDateString()) }}">
                    @error('since') <span class="error">{{ $message }}</span> @enderror
                </td>
            </tr>
            <tr id="untilRow">
                <th><label for="until">Do <span class="required">*</span></label></th>
                <td>
                    <input type="date" name="until" id="until"
                           value="{{ old('until', ($whitelist && $whitelist->until !== '9999-12-31') ? $whitelist->until : '') }}">
                    @error('until') <span class="error">{{ $message }}</span> @enderror
                </td>
            </tr>
            <tr>
                <th><label for="comment">Komentář</label></th>
                <td>
                    <textarea name="comment" id="comment" rows="3" cols="50">{{ old('comment', $whitelist?->comment) }}</textarea>
                    @error('comment') <span class="error">{{ $message }}</span> @enderror
                </td>
            </tr>
        </tbody>
    </table>

    <p>
        <button type="submit">Uložit</button>
        <a href="{{ route('members.show', $member->id) }}">Zrušit</a>
    </p>
    </form>

    <script>
    function toggleUntil() {
        var perm  = document.getElementById('permanent').checked;
        var row   = document.getElementById('untilRow');
        var input = document.getElementById('until');
        row.style.display = perm ? 'none' : '';
        input.required    = !perm;
        if (perm) input.value = '';
    }
    document.addEventListener('DOMContentLoaded', toggleUntil);
    </script>
@endsection
