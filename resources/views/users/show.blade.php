@extends('layouts.app')

@section('title', 'Uživatel: ' . $user->full_name)

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('users.index') }}">Uživatelé</a> &raquo;
        {{ $user->full_name }}
    </div>
@endsection

@section('content')
    <h2>{{ $user->full_name }}</h2>

    @if(session('success'))
        <p class="success">{{ session('success') }}</p>
    @endif
    @if(session('error'))
        <p class="error">{{ session('error') }}</p>
    @endif

    {{-- Action links --}}
    <div style="margin-bottom:1em;">
        @if($canEdit)
            <a href="{{ route('users.edit', $user->id) }}">Upravit</a>
        @endif
        @if($canChangePassword)
            | <a href="{{ route('users.password', $user->id) }}">Změnit heslo</a>
        @endif
        @if($canChangeAppPwd)
            | <a href="{{ route('users.edit', $user->id) }}#application_password">Změnit app. heslo</a>
        @endif
        @if($canViewDevices)
            | <a href="{{ route('devices.by_user', $user->id) }}">Zobrazit zařízení</a>
        @endif
        @if($canViewLoginLogs)
            | <a href="{{ route('login_logs.by_user', $user->id) }}">Logy přihlášení</a>
        @endif
    </div>

    {{-- Two-column layout: info table + ARO groups --}}
    <div style="overflow: hidden; margin-bottom: 20px;">

        {{-- Left: Informace o uživateli --}}
        <table class="extended" cellspacing="0" style="float:left; width:360px; margin-right:20px;">
            <thead>
                <tr><th colspan="2">Informace o uživateli</th></tr>
            </thead>
            <tbody>
                <tr>
                    <th>ID</th>
                    <td>{{ $user->id }}</td>
                </tr>
                <tr>
                    <th>Login</th>
                    <td>{{ $user->login }}</td>
                </tr>
                <tr>
                    <th>Jméno</th>
                    <td>{{ $user->full_name }}</td>
                </tr>
                @if($user->birthday && $user->birthday !== '0000-00-00')
                    <tr>
                        <th>Datum narození</th>
                        <td>{{ $user->birthday }}</td>
                    </tr>
                @endif
                <tr>
                    <th>Typ</th>
                    <td>{{ $user->type == 1 ? 'Hlavní uživatel' : 'Uživatel' }}</td>
                </tr>
                @if($user->comment)
                    <tr>
                        <th>Komentář</th>
                        <td>{{ $user->comment }}</td>
                    </tr>
                @endif
                <tr>
                    <th>Člen</th>
                    <td>
                        @if($user->member)
                            <a href="{{ route('members.show', $user->member_id) }}">{{ $user->member->name }}</a>
                        @else
                            —
                        @endif
                    </td>
                </tr>
                @if($canViewAppPwd)
                    <tr>
                        <th>Aplikační heslo</th>
                        <td>{{ $user->application_password ?: '—' }}</td>
                    </tr>
                @endif

                @php $contacts = $user->contacts()->with('enumType')->get(); @endphp
                @if($contacts->isNotEmpty())
                    <tr>
                        <th colspan="2" style="background:#e8e8e8;">Kontaktní informace</th>
                    </tr>
                    @foreach($contacts as $contact)
                        <tr>
                            <th>{{ $contact->enumType?->value ?? $contact->type }}</th>
                            <td>{{ $contact->value }}</td>
                        </tr>
                    @endforeach
                @endif
            </tbody>
        </table>

        {{-- Right: Přístupová práva --}}
        <div style="float:left;">
            <table class="extended" cellspacing="0" style="width:260px;">
                <tr><th colspan="2" style="background:#e8e8e8;">Přístupová práva</th></tr>
                @forelse($aroGroups as $group)
                    <tr><td>{{ $group->name }}</td></tr>
                @empty
                    <tr><td>Žádné skupiny</td></tr>
                @endforelse
            </table>
        </div>

    </div>
    <div style="clear:both;"></div>

    @if($canViewContacts)
        <p>
            <a href="{{ route('contacts.show_by_user', $user->id) }}">Přidat/upravit kontakty</a>
        </p>
    @endif

    {{-- Devices as admin --}}
    @if($deviceAdmins->count() > 0)
        <h3>Zařízení jako admin</h3>
        <table class="extended" cellspacing="0">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Název</th>
                    <th>Akce</th>
                </tr>
            </thead>
            <tbody>
                @foreach($deviceAdmins as $da)
                    <tr>
                        <td>{{ $da->device->id ?? '—' }}</td>
                        <td>{{ $da->device->name ?? '—' }}</td>
                        <td>
                            @if($da->device)
                                <a href="{{ route('devices.show', $da->device_id) }}">Detail</a>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- Devices as engineer --}}
    @if($deviceEngineers->count() > 0)
        <h3>Zařízení jako engineer</h3>
        <table class="extended" cellspacing="0">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Název</th>
                    <th>Akce</th>
                </tr>
            </thead>
            <tbody>
                @foreach($deviceEngineers as $de)
                    <tr>
                        <td>{{ $de->device->id ?? '—' }}</td>
                        <td>{{ $de->device->name ?? '—' }}</td>
                        <td>
                            @if($de->device)
                                <a href="{{ route('devices.show', $de->device_id) }}">Detail</a>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
