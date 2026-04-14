@extends('layouts.app')

@section('title', 'Automatické stahování — ' . $account->name)

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('bank_accounts.index') }}">Bankovní účty</a> &raquo;
        <a href="{{ route('bank_accounts.show', $account->id) }}">{{ $account->name }}</a> &raquo;
        Automatické stahování výpisů
    </div>
@endsection

@section('content')
    <h2>Automatické stahování výpisů — {{ $account->name }}</h2>
    <p><a href="{{ route('bank_accounts.show', $account->id) }}">&laquo; Zpět na účet</a></p>

    @if(session('success'))
        <div class="message_ok"><p>{{ session('success') }}</p></div>
    @endif
    @if($errors->any())
        <div class="message_error">
            @foreach($errors->all() as $err)
                <p>{{ $err }}</p>
            @endforeach
        </div>
    @endif

    {{-- Existing rules --}}
    <h3>Aktuální pravidla</h3>

    @if($rules->isNotEmpty())
        <table class="extended" cellspacing="0">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Typ</th>
                    <th>Atributy</th>
                    <th>E-mail</th>
                    <th>SMS</th>
                    <th>Akce</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rules as $rule)
                    <tr>
                        <td>{{ $rule->id }}</td>
                        <td>{{ $rule->typeLabel() }}</td>
                        <td>{{ $rule->attributeLabel() }}</td>
                        <td>{{ $rule->email_enabled ? 'Ano' : 'Ne' }}</td>
                        <td>{{ $rule->sms_enabled   ? 'Ano' : 'Ne' }}</td>
                        <td>
                            @if($canDelete)
                                <form method="POST" action="{{ route('bank_accounts.auto_downloads.destroy', $rule->id) }}" style="display:inline">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit"
                                            style="background:none;border:none;cursor:pointer;padding:0;color:#00c;text-decoration:underline"
                                            onclick="return confirm('Smazat pravidlo?')">Smazat</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p>Žádná pravidla.</p>
    @endif

    {{-- Add rule form --}}
    @if($canAdd)
        <h3>Přidat pravidlo</h3>

        <form method="POST" action="{{ route('bank_accounts.auto_downloads.store', $account->id) }}" class="form" id="autoDownloadForm">
            @csrf
            <table class="extended" cellspacing="0">
                <tbody>
                    <tr>
                        <th><label for="type">Typ <span class="required">*</span></label></th>
                        <td>
                            <select name="type" id="typeSelect" onchange="updateAttributeFields()">
                                @foreach($typeLabels as $val => $label)
                                    <option value="{{ $val }}" @selected(old('type') == $val)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </td>
                    </tr>

                    {{-- Attribute fields (shown/hidden via JS) --}}
                    @php
                        // Max 2 attributes across all types
                        $maxAttrs = 2;
                    @endphp
                    @for($i = 0; $i < $maxAttrs; $i++)
                        <tr id="attrRow{{ $i }}" style="display:none">
                            <th><label id="attrLabel{{ $i }}" for="attribute_{{ $i }}">Atribut {{ $i + 1 }}</label></th>
                            <td>
                                <input type="number" name="attribute[{{ $i }}]" id="attribute_{{ $i }}"
                                       value="{{ old('attribute.' . $i) }}" min="0" max="31">
                            </td>
                        </tr>
                    @endfor

                    <tr>
                        <th><label for="email_enabled">E-mail</label></th>
                        <td><input type="checkbox" name="email_enabled" id="email_enabled" value="1"
                                   @checked(old('email_enabled'))></td>
                    </tr>
                    <tr>
                        <th><label for="sms_enabled">SMS</label></th>
                        <td><input type="checkbox" name="sms_enabled" id="sms_enabled" value="1"
                                   @checked(old('sms_enabled'))></td>
                    </tr>
                </tbody>
            </table>

            <p>
                <button type="submit">Přidat</button>
                <a href="{{ route('bank_accounts.show', $account->id) }}">Zrušit</a>
            </p>
        </form>

        <script>
        var typeAttrs = @json($typeAttrs);

        function updateAttributeFields() {
            var type = parseInt(document.getElementById('typeSelect').value);
            var attrs = typeAttrs[type] || [];

            for (var i = 0; i < 2; i++) {
                var row   = document.getElementById('attrRow' + i);
                var label = document.getElementById('attrLabel' + i);
                var input = document.getElementById('attribute_' + i);

                if (i < attrs.length) {
                    row.style.display = '';
                    label.textContent  = attrs[i].name + ' *';
                    input.min          = attrs[i].min;
                    input.max          = attrs[i].max;
                    input.required     = true;
                } else {
                    row.style.display  = 'none';
                    input.required     = false;
                }
            }
        }

        // Init on page load
        document.addEventListener('DOMContentLoaded', updateAttributeFields);
        </script>
    @endif
@endsection
