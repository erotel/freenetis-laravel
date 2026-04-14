@extends('layouts.app')

@section('title', $action === 'create' ? 'Přidat přerušení členství' : 'Upravit přerušení členství')

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('members.index') }}">Členové</a> &raquo;
        <a href="{{ route('members.show', $member->id) }}">{{ $member->name }}</a> &raquo;
        {{ $action === 'create' ? 'Přidat přerušení členství' : 'Upravit přerušení členství' }}
    </div>
@endsection

@section('content')
    <h2>{{ $action === 'create' ? 'Přidat přerušení členství' : 'Upravit přerušení členství' }} — {{ $member->name }}</h2>

    @if($errors->any())
        <div class="message_error">
            @foreach($errors->all() as $err)
                <p>{{ $err }}</p>
            @endforeach
        </div>
    @endif

    @if($action === 'create')
        <form method="POST" action="{{ route('membership-interrupts.store', $member->id) }}" class="form">
    @else
        <form method="POST" action="{{ route('membership-interrupts.update', $record->id) }}" class="form">
        @method('PUT')
    @endif
    @csrf

    <table class="extended" cellspacing="0">
        <tbody>
            <tr>
                <th><label for="from">Datum od <span class="required">*</span></label></th>
                <td>
                    <input type="date" id="from" name="from"
                           value="{{ old('from', $record ? substr($record->activation_date, 0, 10) : '') }}"
                           required>
                    @error('from') <span class="error">{{ $message }}</span> @enderror
                    <small style="color:#888;">První den přerušení.</small>
                </td>
            </tr>
            <tr>
                <th><label for="to">Datum do <span class="required">*</span></label></th>
                <td>
                    <input type="date" id="to" name="to"
                           value="{{ old('to', $record ? substr($record->deactivation_date, 0, 10) : '') }}"
                           required>
                    @error('to') <span class="error">{{ $message }}</span> @enderror
                    <small style="color:#888;">Poslední den přerušení.</small>
                </td>
            </tr>
            <tr>
                <th><label for="comment">Komentář <span class="required">*</span></label></th>
                <td>
                    <textarea id="comment" name="comment" rows="3" cols="50"
                              maxlength="250">{{ old('comment', $record?->comment) }}</textarea>
                    @error('comment') <span class="error">{{ $message }}</span> @enderror
                </td>
            </tr>
            <tr>
                <th>Ukončit členství po skončení přerušení</th>
                <td>
                    @php
                        $checked = old('end_after_interrupt_end') !== null
                            ? (bool) old('end_after_interrupt_end')
                            : ($record ? (bool) $record->end_after_interrupt_end : false);
                    @endphp
                    <input type="checkbox" id="end_after_interrupt_end"
                           name="end_after_interrupt_end" value="1"
                           @checked($checked)>
                    <label for="end_after_interrupt_end">
                        Nastaví datum odchodu člena na datum konce přerušení.
                    </label>
                </td>
            </tr>
        </tbody>
    </table>

    <p>
        <button type="submit">Uložit</button>
        <a href="{{ route('members.show', $member->id) }}">Zrušit</a>
    </p>
    </form>
@endsection
