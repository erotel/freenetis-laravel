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

    @if($mainUser)
        <h3>Informace o hlavním uživateli</h3>
        <br>

        @if($canViewUser)
            <a href="{{ route('users.show', $mainUser->id) }}">Zobrazit</a> |
        @endif
        @if($canEditUser)
            <a href="{{ route('users.edit', $mainUser->id) }}">Upravit</a> |
            <a href="{{ route('users.password', $mainUser->id) }}">Změnit heslo</a>
        @endif
        <br><br>

        <div style="overflow: hidden;">

            <table class="extended" cellspacing="0" style="float: left; width: 360px; margin-right: 20px;">
                <tr><th colspan="2" style="background:#e8e8e8;">Základní informace</th></tr>
                <tr><th>ID uživatele</th><td>{{ $mainUser->id }}</td></tr>
                <tr><th>Přihl. jméno</th><td>{{ $mainUser->login }}</td></tr>
                <tr><th>Jméno</th><td>{{ $mainUser->full_name }}</td></tr>
                @if($mainUser->birthday && $mainUser->birthday !== '0000-00-00')
                    <tr><th>Datum narození</th><td>{{ $mainUser->birthday }}</td></tr>
                @endif
                @if($mainUser->comment)
                    <tr><th>Komentář</th><td>{{ $mainUser->comment }}</td></tr>
                @endif
            </table>

            @if($canViewContacts && $contacts->count() > 0)
                <div style="float: left;">
                    <table class="extended" cellspacing="0" style="width: 300px;">
                        <tr><th colspan="2" style="background:#e8e8e8;">Kontaktní informace</th></tr>
                        @foreach($contacts as $contact)
                            <tr>
                                <th>{{ $contact->enumType?->value ?? $contact->type }}</th>
                                <td>{{ $contact->value }}</td>
                            </tr>
                        @endforeach
                    </table>
                    <p>
                        <a href="{{ route('contacts.show_by_user', $mainUser->id) }}">
                            Přidávání/editace dalších kontaktních informací
                        </a>
                    </p>
                </div>
            @endif

        </div>
        <div style="clear: both;"></div>
    @endif

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
