@extends('layouts.app')

@section('title', $action === 'create' ? 'Přidat komentář' : 'Upravit komentář')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        @if($backUrl)
            <a href="{{ $backUrl }}">Zpět</a> &raquo;
        @endif
        {{ $action === 'create' ? 'Přidat komentář' : 'Upravit komentář' }}
    </div>
@endsection

@section('content')
    <h2>{{ $action === 'create' ? 'Přidat komentář k finančnímu stavu člena' : 'Upravit komentář' }}</h2>

    @if($errors->any())
        <div class="message_error">
            @foreach($errors->all() as $err)
                <p>{{ $err }}</p>
            @endforeach
        </div>
    @endif

    @if($action === 'create')
        <form method="POST" action="{{ route('comments.store', $thread->id) }}" class="form">
    @else
        <form method="POST" action="{{ route('comments.update', $comment->id) }}" class="form">
        @method('PUT')
    @endif
    @csrf

    <table class="extended" cellspacing="0">
        <tbody>
            <tr>
                <th><label for="text">Komentář <span class="required">*</span></label></th>
                <td>
                    <textarea id="text" name="text" rows="5" cols="60"
                              maxlength="5000">{{ old('text', $comment?->text) }}</textarea>
                    @error('text') <span class="error">{{ $message }}</span> @enderror
                </td>
            </tr>
        </tbody>
    </table>

    <p>
        <button type="submit">Uložit</button>
        @if($backUrl)
            <a href="{{ $backUrl }}">Zrušit</a>
        @endif
    </p>
    </form>
@endsection
