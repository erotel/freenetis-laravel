@extends('layouts.app')

@section('title', 'Člen: ' . $member->name)

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('members.index') }}">Členové</a> &raquo;
        {{ $member->name }}
    </div>
@endsection

@section('content')
    <h2>{{ $member->name }}</h2>

    <table class="extended" cellspacing="0" style="float:left; width:380px;">
        <thead>
            <tr><th colspan="2">Základní informace</th></tr>
        </thead>
        <tbody>
            <tr>
                <th>ID člena</th>
                <td>{{ $member->id }}</td>
            </tr>
            <tr>
                <th>Název / Jméno</th>
                <td>{{ $member->name }}</td>
            </tr>
            <tr>
                <th>Typ člena</th>
                <td>{{ $member->type_label }}</td>
            </tr>
            <tr>
                <th>Registrace</th>
                <td>{{ $member->registration ? 'Ano' : 'Ne' }}</td>
            </tr>
            @if($member->entrance_date && $member->entrance_date !== '0000-00-00')
                <tr>
                    <th>Datum vstupu</th>
                    <td>{{ $member->entrance_date }}</td>
                </tr>
            @endif
            @if($member->leaving_date && $member->leaving_date !== '0000-00-00')
                <tr>
                    <th>Datum odchodu</th>
                    <td>{{ $member->leaving_date }}</td>
                </tr>
            @endif
            @if($member->organization_identifier)
                <tr>
                    <th>IČO</th>
                    <td>{{ $member->organization_identifier }}</td>
                </tr>
            @endif
            @if($member->vat_organization_identifier)
                <tr>
                    <th>DIČ</th>
                    <td>{{ $member->vat_organization_identifier }}</td>
                </tr>
            @endif
            @if($member->comment)
                <tr>
                    <th>Poznámka</th>
                    <td>{{ $member->comment }}</td>
                </tr>
            @endif
            <tr>
                <th>Zablokován</th>
                <td>{{ $member->locked ? 'Ano' : 'Ne' }}</td>
            </tr>
            @if($variableSymbols->isNotEmpty())
                <tr>
                    <th>Variabilní symboly</th>
                    <td>{{ $variableSymbols->implode(', ') }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    <div style="clear:both; padding-top:1em;">
        @if($canEdit)
            <a href="{{ route('members.edit', $member->id) }}">
                <img src="{{ asset('media/images/icons/gtk_edit.png') }}" alt="Upravit">
                Upravit
            </a>
            &nbsp;
        @endif
        @if($canDelete)
            <form method="POST" action="{{ route('members.destroy', $member->id) }}" style="display:inline;"
                  onsubmit="return confirm('Opravdu smazat člena {{ addslashes($member->name) }}?');">
                @csrf
                @method('DELETE')
                <button type="submit" class="icon-button" title="Smazat">
                    <img src="{{ asset('media/images/icons/delete.png') }}" alt="Smazat">
                    Smazat
                </button>
            </form>
        @endif
    </div>

    @if($member->users->isNotEmpty())
        <h3>Uživatelé</h3>
        <table class="extended" cellspacing="0">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Login</th>
                    <th>Jméno</th>
                    <th>Typ</th>
                    <th>Akce</th>
                </tr>
            </thead>
            <tbody>
                @foreach($member->users as $user)
                    <tr>
                        <td>{{ $user->id }}</td>
                        <td><a href="{{ route('users.show', $user->id) }}">{{ $user->login }}</a></td>
                        <td>{{ $user->full_name }}</td>
                        <td>{{ $user->type == 1 ? 'Hlavní uživatel' : 'Uživatel' }}</td>
                        <td>
                            <a href="{{ route('users.show', $user->id) }}" title="Detail">
                                <img src="{{ asset('media/images/icons/con_info.png') }}" alt="Detail">
                            </a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <p>
        <a href="{{ route('users.create', ['member_id' => $member->id]) }}">
            <img src="{{ asset('media/images/icons/ico_add.gif') }}" alt="Přidat">
            Přidat uživatele
        </a>
    </p>
@endsection
