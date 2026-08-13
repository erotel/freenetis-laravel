@extends('layouts.app')
@section('title', 'Uživatel: ' . $user->full_name)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('users.index') }}">Uživatelé</a> &raquo; {{ $user->full_name }}
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row">
    <h2>{{ $user->full_name }}</h2>
    @if($user->type == 1)
        <span class="m-badge m-badge-amber">Hlavní uživatel</span>
    @else
        <span class="m-badge m-badge-gray">Uživatel</span>
    @endif
</div>

<div class="m-actions">
    @if($canEdit) <a class="m-btn" href="{{ route('users.edit', $user->id) }}">Upravit</a> @endif
    @if($canChangePassword) <a class="m-btn" href="{{ route('users.password', $user->id) }}">Změnit heslo</a> @endif
    @if($canChangeAppPwd) <a class="m-btn" href="{{ route('users.edit', $user->id) }}#application_password">Změnit app. heslo</a> @endif
    @if($canViewDevices) <a class="m-btn" href="{{ route('devices.by_user', $user->id) }}">Zařízení</a> @endif
    @if($canViewLoginLogs) <a class="m-btn" href="{{ route('login_logs.by_user', $user->id) }}">Logy přihlášení</a> @endif
    @if($canResetMfa && $mfaEnabled)
        <form method="POST" action="{{ route('users.mfa.reset', $user->id) }}" style="display:inline"
              onsubmit="return confirm('Resetovat dvoufázové přihlášení tohoto uživatele? Bude si ho muset zřídit znovu.')">
            @csrf
            <button class="m-btn m-btn-danger" title="Když uživatel ztratil telefon i záložní kódy">Resetovat MFA</button>
        </form>
    @endif
</div>
@if($mfaEnabled)
    <div style="font-size:13px;color:#1a7f37;margin-top:-6px;margin-bottom:8px">🔒 Dvoufázové přihlášení: zapnuto</div>
@endif

<div class="m-grid2">
    <div class="m-card">
        <div class="m-card-title">Informace o uživateli</div>
        <div class="m-field"><span class="m-field-label">ID</span><span class="m-field-value">{{ $user->id }}</span></div>
        <div class="m-field"><span class="m-field-label">Login</span><span class="m-field-value">{{ $user->login }}</span></div>
        <div class="m-field"><span class="m-field-label">Jméno</span><span class="m-field-value">{{ $user->full_name }}</span></div>
        @if($user->birthday && $user->birthday !== '0000-00-00')
        <div class="m-field"><span class="m-field-label">Datum narození</span><span class="m-field-value">{{ $user->birthday }}</span></div>
        @endif
        @if($user->comment)
        <div class="m-field"><span class="m-field-label">Komentář</span><span class="m-field-value">{{ $user->comment }}</span></div>
        @endif
        <div class="m-field">
            <span class="m-field-label">Člen</span>
            <span class="m-field-value">
                @if($user->member) <a href="{{ route('members.show', $user->member_id) }}">{{ $user->member->name }}</a>
                @else — @endif
            </span>
        </div>
        @if($canViewAppPwd)
        <div class="m-field"><span class="m-field-label">Aplikační heslo</span><span class="m-field-value">{{ $user->application_password ?: '—' }}</span></div>
        @endif

        @php $contacts = $user->contacts()->with('enumType')->get(); @endphp
        @if($contacts->isNotEmpty())
        <div class="m-section" style="margin-top:12px">Kontaktní informace</div>
        @foreach($contacts as $contact)
        <div class="m-field">
            <span class="m-field-label">{{ $contact->enumType?->value ?? $contact->type }}</span>
            <span class="m-field-value">{{ $contact->value }}</span>
        </div>
        @endforeach
        @endif

        @if($canViewContacts)
        <div style="margin-top:8px">
            <a class="m-link" href="{{ route('contacts.show_by_user', $user->id) }}">Přidat / upravit kontakty</a>
        </div>
        @endif
    </div>

    <div class="m-card">
        <div class="m-card-title">Přístupová práva</div>
        @forelse($aroGroups as $group)
        <div class="m-field"><span class="m-field-value">{{ $group->name }}</span></div>
        @empty
        <div style="font-size:16px;color:#aaa;padding:6px 0">Žádné skupiny.</div>
        @endforelse
    </div>
</div>

@if($deviceAdmins->count() > 0)
<div class="m-section">Zařízení jako admin</div>
<div class="m-card" style="padding:0;overflow-x:auto;margin-bottom:16px">
<table class="m-table" style="margin-bottom:0">
    <thead><tr><th style="width:60px">ID</th><th>Název</th><th style="width:80px">Akce</th></tr></thead>
    <tbody>
        @foreach($deviceAdmins as $da)
        <tr>
            <td>{{ $da->device->id ?? '—' }}</td>
            <td>{{ $da->device->name ?? '—' }}</td>
            <td>@if($da->device)<a class="m-link-sm" href="{{ route('devices.show', $da->device_id) }}">Detail</a>@endif</td>
        </tr>
        @endforeach
    </tbody>
</table>
</div>
@endif

@if($deviceEngineers->count() > 0)
<div class="m-section">Zařízení jako engineer</div>
<div class="m-card" style="padding:0;overflow-x:auto;margin-bottom:16px">
<table class="m-table" style="margin-bottom:0">
    <thead><tr><th style="width:60px">ID</th><th>Název</th><th style="width:80px">Akce</th></tr></thead>
    <tbody>
        @foreach($deviceEngineers as $de)
        <tr>
            <td>{{ $de->device->id ?? '—' }}</td>
            <td>{{ $de->device->name ?? '—' }}</td>
            <td>@if($de->device)<a class="m-link-sm" href="{{ route('devices.show', $de->device_id) }}">Detail</a>@endif</td>
        </tr>
        @endforeach
    </tbody>
</table>
</div>
@endif

</div>
@endsection
