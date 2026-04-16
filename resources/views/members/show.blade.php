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

@section('styles')
<style>
.member-page { padding: 0 0 2rem; }
.member-title-row { display: flex; align-items: center; gap: 10px; margin-bottom: 4px; }
.member-title-row h2 { margin: 0; font-size: 22px; font-weight: 500; }
.member-id { font-size: 13px; color: #888; margin-bottom: 1.25rem; }
.m-badge { display: inline-flex; align-items: center; font-size: 12px; font-weight: 500; padding: 3px 9px; border-radius: 20px; }
.m-badge-customer  { background: #E6F1FB; color: #185FA5; }
.m-badge-member    { background: #EAF3DE; color: #3B6D11; }
.m-badge-former    { background: #F1EFE8; color: #5F5E5A; }
.m-badge-applicant { background: #FAEEDA; color: #854F0B; }
.m-actions { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 1.5rem; }
.m-btn { font-size: 13px; padding: 6px 12px; border-radius: 6px; border: 1px solid #ddd; background: transparent; color: #555; cursor: pointer; text-decoration: none; display: inline-block; }
.m-btn:hover { background: #f5f5f5; color: #222; }
.m-btn-danger  { border-color: #f5c6c6; color: #c0392b; }
.m-btn-danger:hover  { background: #fdf0f0; }
.m-btn-success { border-color: #c3e6c3; color: #27ae60; }
.m-btn-success:hover { background: #f0faf0; }
.m-metrics { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 16px; }
.m-metric { background: #f7f7f5; border-radius: 8px; padding: 12px 14px; }
.m-metric-label { font-size: 12px; color: #888; margin-bottom: 4px; }
.m-metric-value { font-size: 20px; font-weight: 500; color: #222; }
.m-metric-value.green { color: #27ae60; }
.m-metric-value.red   { color: #c0392b; }
.m-metric-value.sm    { font-size: 15px; }
.m-grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
.m-card { background: #fff; border: 1px solid #e8e8e8; border-radius: 10px; padding: 1rem 1.25rem; }
.m-card-title { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: #aaa; margin-bottom: 12px; }
.m-field { display: flex; justify-content: space-between; align-items: baseline; padding: 6px 0; border-bottom: 1px solid #f0f0f0; gap: 12px; }
.m-field:last-child { border-bottom: none; }
.m-field-label { font-size: 13px; color: #777; white-space: nowrap; flex-shrink: 0; }
.m-field-value { font-size: 13px; color: #222; text-align: right; word-break: break-word; }
.m-field-value a { color: #2980b9; text-decoration: none; font-size: 12px; }
.m-field-value a:hover { text-decoration: underline; }
.m-section { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: #aaa; padding: 4px 0 8px; border-bottom: 1px solid #eee; margin: 16px 0 12px; }
.m-user-row { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 1px solid #f0f0f0; }
.m-user-row:last-child { border-bottom: none; }
.m-avatar { width: 36px; height: 36px; border-radius: 50%; background: #E6F1FB; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 600; color: #185FA5; flex-shrink: 0; }
.m-user-info { flex: 1; min-width: 0; }
.m-user-name { font-size: 14px; font-weight: 500; color: #222; }
.m-user-login { font-size: 12px; color: #aaa; }
.m-user-actions { display: flex; flex-wrap: wrap; gap: 8px; }
.m-link { font-size: 12px; color: #2980b9; text-decoration: none; }
.m-link:hover { text-decoration: underline; }
.m-ip-row { display: flex; align-items: center; justify-content: space-between; padding: 7px 0; border-bottom: 1px solid #f0f0f0; font-size: 13px; flex-wrap: wrap; gap: 6px; }
.m-ip-row:last-child { border-bottom: none; }
.m-ip-addr { font-family: monospace; color: #222; }
.m-warning { background: #fff8e1; border: 1px solid #ffe082; border-radius: 8px; padding: 10px 14px; font-size: 13px; color: #7a5c00; margin-bottom: 1.25rem; }
@media (max-width: 700px) {
  .m-metrics { grid-template-columns: 1fr 1fr; }
  .m-grid2   { grid-template-columns: 1fr; }
}
</style>
@endsection

@section('content')
<div class="member-page">

{{-- Warning pro čekatele --}}
@if(in_array($member->type, [1, 17, 18]))
<div class="m-warning">
    &#9888;
    <strong>
        @if($member->type == 17) Čekající člen
        @elseif($member->type == 18) Čekající zákazník
        @else Žadatel
        @endif
    </strong>
    — přihláška/smlouva dosud nepodepsána.
    Člen nemá přístup k internetu ani mu nejsou strháváni poplatky.
    Po podpisu změňte typ přes <a href="{{ route('members.edit', $member->id) }}">Upravit</a>.
</div>
@endif

{{-- Titulek --}}
<div class="member-title-row">
    <h2>{{ $member->name }}</h2>
    @php
        $badgeClass = match(true) {
            in_array($member->type, [2, 18])  => 'm-badge-customer',
            in_array($member->type, [90, 3])  => 'm-badge-member',
            in_array($member->type, [15, 16]) => 'm-badge-former',
            default                           => 'm-badge-applicant',
        };
    @endphp
    <span class="m-badge {{ $badgeClass }}">{{ $member->typeLabel() }}</span>
</div>
<div class="member-id">ID člena: {{ $member->id }}</div>

{{-- Akční tlačítka --}}
<div class="m-actions">
    @if($canEdit)
    <a class="m-btn" href="{{ route('members.edit', $member->id) }}">Upravit</a>
    @endif
    @if($creditAccount)
    <a class="m-btn" href="{{ route('accounts.show', $creditAccount->id) }}">Detail účtu</a>
    @endif
    @if($creditAccount && $canViewTransfers)
    <a class="m-btn" href="{{ route('transfers.by_account', $creditAccount->id) }}">Zobrazit převody</a>
    @endif
    @if($canViewFees)
    <a class="m-btn" href="{{ route('members_fees.by_member', $member->id) }}">Zobrazit tarify</a>
    @endif
    @if($canViewAllowedSubnets)
    <a class="m-btn" href="{{ route('allowed_subnets.by_member', $member->id) }}">Povolené podsítě</a>
    @endif
    @if($canViewInvoices)
    <a class="m-btn" href="{{ route('invoices.by_member', $member->id) }}">Faktury</a>
    @endif
    @if($mainUser && ($canEditUser || auth()->id() == $mainUser->id))
    <a class="m-btn" href="{{ route('users.password', $mainUser->id) }}">Změnit heslo</a>
    @endif
    @if($canNotify)
    <a class="m-btn" href="{{ route('notifications.member', $member->id) }}">Oznámení</a>
    @endif
    @if($canViewWhitelists)
    <a class="m-btn" href="{{ route('member_whitelists.index', ['member_id' => $member->id]) }}">Bílé listiny</a>
    @endif
    @if($canEditRedirect)
    <a class="m-btn" href="{{ route('redirects.activate-member', $member->id) }}" style="color:#c60;">Přesměrovat</a>
    @endif
    @if($canExportRegistration && in_array($member->type, [2, 90]))
    <form method="GET" style="display:inline;">
        <select onchange="var t=this.value;if(t)window.open('{{ url('members/'.$member->id.'/registration-export') }}/'+t,'_blank');this.value='';" class="m-btn" style="padding:5px 8px;">
            <option value="">— Export PDF —</option>
            @if($member->type == 90)
                <option value="registration">Přihláška</option>
                <option value="end">Ukončení členství</option>
            @elseif($member->type == 2)
                <option value="contract_end">Výpověď smlouvy</option>
            @endif
        </select>
    </form>
    @endif
    @if($canEdit && in_array($member->type, [17, 18]) && $member->registration)
    <form method="POST" action="{{ route('members.approve', $member->id) }}" style="display:inline">
        @csrf
        <button class="m-btn m-btn-success" type="submit"
                onclick="return confirm('Schválit čekatele?')">✓ Schválit čekatele</button>
    </form>
    @endif
    @if($canDelete)
        @if(in_array($member->type, [17, 18]))
        <form method="POST" action="{{ route('members.destroy', $member->id) }}" style="display:inline;">
            @csrf @method('DELETE')
            <button class="m-btn m-btn-danger" type="submit"
                    onclick="return confirm('Smazat čekajícího člena {{ addslashes($member->name) }}?')">✕ Smazat</button>
        </form>
        @elseif(!in_array($member->type, [15, 16]))
        <a class="m-btn m-btn-danger" href="{{ route('members.end-membership', $member->id) }}">✕ Ukončit</a>
        @else
        <form method="POST" action="{{ route('members.destroy', $member->id) }}" style="display:inline;">
            @csrf @method('DELETE')
            <button class="m-btn m-btn-danger" type="submit"
                    onclick="return confirm('TRVALE smazat člena {{ addslashes($member->name) }}?')">✕ Trvale smazat</button>
        </form>
        @endif
    @endif
    @if(in_array($member->type, [15, 16]))
    <form method="POST" action="{{ route('members.restore', $member->id) }}" style="display:inline">
        @csrf
        <button class="m-btn m-btn-success" type="submit"
                onclick="return confirm('Obnovit člena {{ addslashes($member->name) }}?')">↩ Obnovit</button>
    </form>
    @endif
</div>

{{-- Metric karty --}}
@if($creditAccount && !in_array($member->type, [1, 17, 18]))
<div class="m-metrics">
    <div class="m-metric">
        <div class="m-metric-label">Současný kredit</div>
        <div class="m-metric-value {{ $creditAccount->balance >= 0 ? 'green' : 'red' }}">
            {{ number_format($creditAccount->balance, 0, ',', ' ') }} Kč
        </div>
    </div>
    <div class="m-metric">
        <div class="m-metric-label">Zaplaceno do</div>
        <div class="m-metric-value sm {{ $expirationDate && $expirationDate >= now()->format('Y-m-d') ? 'green' : 'red' }}">
            {{ $expirationDate ? \Carbon\Carbon::parse($expirationDate)->format('d.m.Y') : '—' }}
        </div>
    </div>
    <div class="m-metric">
        <div class="m-metric-label">Měsíční platba</div>
        <div class="m-metric-value sm">
            @if($activeMemberFee && $activeMemberFee->fee)
                {{ number_format($activeMemberFee->fee->fee, 0, ',', ' ') }} Kč
            @else —
            @endif
        </div>
    </div>
</div>
@endif

{{-- Základní informace + Adresa --}}
<div class="m-grid2">
    <div class="m-card">
        <div class="m-card-title">Základní informace</div>
        <div class="m-field"><span class="m-field-label">ID člena</span><span class="m-field-value">{{ $member->id }}</span></div>
        <div class="m-field"><span class="m-field-label">Název / Jméno</span><span class="m-field-value">{{ $member->name }}</span></div>
        <div class="m-field"><span class="m-field-label">Typ člena</span><span class="m-field-value">{{ $member->type_label }}</span></div>
        @if($variableSymbols->isNotEmpty() || $creditAccount)
        <div class="m-field">
            <span class="m-field-label">Variabilní symboly</span>
            <span class="m-field-value">
                {{ $variableSymbols->implode(', ') }}
                @if($creditAccount) <a href="{{ route('variable_symbols.by_account', $creditAccount->id) }}">editace</a> @endif
            </span>
        </div>
        @if($variableSymbols->isNotEmpty())
        <div class="m-field"><span class="m-field-label">OKU kód</span><span class="m-field-value">{{ $variableSymbols->implode(', ') }}</span></div>
        @endif
        @endif
        @if($member->entrance_date && $member->entrance_date !== '0000-00-00')
        <div class="m-field"><span class="m-field-label">Datum vstupu</span><span class="m-field-value">{{ $member->entrance_date }}</span></div>
        @endif
        @if($member->leaving_date && $member->leaving_date !== '0000-00-00' && $member->leaving_date !== '9999-12-31')
        <div class="m-field"><span class="m-field-label">Datum odchodu</span><span class="m-field-value">{{ $member->leaving_date }}</span></div>
        @endif
        @if($member->organization_identifier)
        <div class="m-field"><span class="m-field-label">IČO</span><span class="m-field-value">{{ $member->organization_identifier }}</span></div>
        @endif
        @if($member->vat_organization_identifier)
        <div class="m-field"><span class="m-field-label">DIČ</span><span class="m-field-value">{{ $member->vat_organization_identifier }}</span></div>
        @endif
        <div class="m-field">
            <span class="m-field-label">{{ in_array($member->type, [2, 3, 16, 18]) ? 'Smlouva' : 'Přihláška' }}</span>
            <span class="m-field-value">{{ $member->registration ? 'ano' : 'ne' }}</span>
        </div>
        <div class="m-field"><span class="m-field-label">Přístup do systému</span><span class="m-field-value">{{ $member->locked ? 'Zamčen' : 'Odemčen' }}</span></div>
        @if($member->comment)
        <div class="m-field"><span class="m-field-label">Komentář</span><span class="m-field-value">{{ $member->comment }}</span></div>
        @endif
    </div>

    <div>
        <div class="m-card" style="margin-bottom:16px">
            <div class="m-card-title">Adresa</div>
            @if($member->addressPoint)
                @if($member->addressPoint->street)
                <div class="m-field">
                    <span class="m-field-label">Ulice</span>
                    <span class="m-field-value">{{ $member->addressPoint->street->street }} {{ $member->addressPoint->street_number }}</span>
                </div>
                @endif
                @if($member->addressPoint->town)
                <div class="m-field">
                    <span class="m-field-label">Město</span>
                    <span class="m-field-value">{{ $member->addressPoint->town->town }}, {{ $member->addressPoint->town->zip_code }}</span>
                </div>
                @endif
                <div class="m-field"><span class="m-field-label">Země</span><span class="m-field-value">Czech Republic</span></div>
            @else
                <div style="font-size:13px;color:#aaa;padding:6px 0">—</div>
            @endif
        </div>

        @if($canViewQos && $member->speedClass)
        <div class="m-card">
            <div class="m-card-title">QoS</div>
            <div class="m-field">
                <span class="m-field-label">Třída</span>
                <span class="m-field-value"><a href="{{ route('speed_classes.index') }}" class="m-link">{{ $member->speedClass->name }}</a></span>
            </div>
            <div class="m-field">
                <span class="m-field-label">Max (D/U)</span>
                <span class="m-field-value">{{ \App\Models\SpeedClass::formatPair($member->speedClass->d_ceil, $member->speedClass->u_ceil) }}</span>
            </div>
            <div class="m-field">
                <span class="m-field-label">Min (D/U)</span>
                <span class="m-field-value">{{ \App\Models\SpeedClass::formatPair($member->speedClass->d_rate, $member->speedClass->u_rate) }}</span>
            </div>
        </div>
        @endif
    </div>
</div>

{{-- Komentáře k účtu --}}
@if($creditAccount && $accountCommentsList->count() && $canComment)
<div class="m-section">Komentáře k účtu</div>
<div class="m-card" style="margin-bottom:16px">
    @foreach($accountCommentsList as $ac)
    <div class="m-field" style="align-items:flex-start; flex-direction:column; gap:2px;">
        <div style="font-size:12px;color:#888;">
            <strong>{{ $ac->user_name }}</strong>
            ({{ \Carbon\Carbon::parse($ac->datetime)->format('d.m.Y') }})
            @if($canEditComment) <a class="m-link" href="{{ route('comments.edit', $ac->id) }}">Upravit</a> @endif
            @if($canDeleteComment)
            <form method="POST" action="{{ route('comments.destroy', $ac->id) }}" style="display:inline;">
                @csrf @method('DELETE')
                <button type="submit" style="background:none;border:none;padding:0;color:#c00;cursor:pointer;font-size:12px;"
                        onclick="return confirm('Smazat komentář?')">Smazat</button>
            </form>
            @endif
        </div>
        <div style="font-size:13px;">{{ $ac->text }}</div>
    </div>
    @endforeach
    @if($canComment)
    <div style="margin-top:8px;">
        @if($creditAccount->comments_thread_id)
        <a class="m-link" href="{{ route('comments.add', $creditAccount->comments_thread_id) }}">+ Přidat komentář</a>
        @else
        <a class="m-link" href="{{ route('comments.add-thread', ['type' => 'account', 'fkId' => $creditAccount->id]) }}">+ Přidat komentář</a>
        @endif
    </div>
    @endif
</div>
@endif

{{-- Aktivní přesměrování --}}
@if($canViewRedirect && $memberRedirections->isNotEmpty())
<div class="m-section">Aktivní přesměrování</div>
<div class="m-card" style="margin-bottom:16px">
    @foreach($memberRedirections as $msgId => $group)
        @foreach($group as $r)
        <div class="m-ip-row">
            <span class="m-ip-addr">{{ $r->ip_address }}</span>
            <span style="font-size:13px;color:#555;">{{ $r->msg_name }}</span>
            <span style="font-size:12px;color:#aaa;">{{ \Carbon\Carbon::parse($r->datetime)->format('d.m.Y H:i') }}</span>
            @if($canDeleteRedirect)
            <form method="POST" action="{{ route('redirects.delete', [$r->ip_address_id, $r->message_id]) }}" style="display:inline">
                @csrf @method('DELETE')
                <button type="submit" style="background:none;border:none;padding:0;color:#c00;cursor:pointer;font-size:12px;"
                        onclick="return confirm('Zrušit přesměrování {{ $r->ip_address }}?')">Zrušit</button>
            </form>
            @endif
        </div>
        @endforeach
    @endforeach
</div>
@endif

{{-- Přerušení členství --}}
@if($canViewInterrupts && $interrupts->count() > 0)
<div class="m-section">Přerušení členství</div>
<div class="m-card" style="margin-bottom:16px">
    @foreach($interrupts as $int)
    <div class="m-field">
        <span class="m-field-label">{{ $int->activation_date }} – {{ $int->deactivation_date ?? '?' }}</span>
        <span class="m-field-value" style="display:flex;gap:8px;align-items:center;">
            {{ $int->comment }}
            @if($canEditInterrupts)
            <a class="m-link" href="{{ route('membership-interrupts.edit', $int->id) }}">Upravit</a>
            <form method="POST" action="{{ route('membership-interrupts.destroy', $int->id) }}" style="display:inline">
                @csrf @method('DELETE')
                <button type="submit" style="background:none;border:none;padding:0;color:#c00;cursor:pointer;font-size:12px;"
                        onclick="return confirm('Smazat přerušení #{{ $int->id }}?')">Smazat</button>
            </form>
            @endif
        </span>
    </div>
    @endforeach
    @if($canEditInterrupts)
    <div style="margin-top:8px;">
        <a class="m-link" href="{{ route('membership-interrupts.create', $member->id) }}">+ Přidat přerušení</a>
    </div>
    @endif
</div>
@elseif($canEditInterrupts)
<div style="margin-bottom:12px;">
    <a class="m-link" href="{{ route('membership-interrupts.create', $member->id) }}">+ Přidat přerušení členství</a>
</div>
@endif

{{-- Hlavní uživatel --}}
@if($mainUser)
<div class="m-section">Hlavní uživatel</div>
<div class="m-card" style="margin-bottom:16px">
    @php $initials = strtoupper(substr($mainUser->name ?? 'U', 0, 1) . substr($mainUser->surname ?? '', 0, 1)); @endphp
    <div class="m-user-row">
        <div class="m-avatar">{{ $initials }}</div>
        <div class="m-user-info">
            <div class="m-user-name">{{ $mainUser->full_name }}</div>
            <div class="m-user-login">{{ $mainUser->login }}</div>
        </div>
        <div class="m-user-actions">
            @if($canViewUser) <a class="m-link" href="{{ route('users.show', $mainUser->id) }}">Zobrazit</a> @endif
            @if($canEditUser) <a class="m-link" href="{{ route('users.edit', $mainUser->id) }}">Upravit</a> @endif
            @if($canViewDevices) <a class="m-link" href="{{ route('devices.by_user', $mainUser->id) }}">Zařízení</a> @endif
            @if($canEditUser || auth()->id() == $mainUser->id)
            <a class="m-link" href="{{ route('users.password', $mainUser->id) }}">Změnit heslo</a>
            @endif
        </div>
    </div>
    @if($canViewContacts && $contacts->count() > 0)
    <div style="padding-top:8px">
        @foreach($contacts as $contact)
        <div class="m-field">
            <span class="m-field-label">{{ $contact->enumType?->value ?? $contact->type }}</span>
            <span class="m-field-value">{{ $contact->value }}</span>
        </div>
        @endforeach
        <div style="margin-top:6px;">
            <a class="m-link" href="{{ route('contacts.show_by_user', $mainUser->id) }}">Přidávání/editace kontaktů</a>
        </div>
    </div>
    @endif
</div>
@endif

{{-- Uživatelé --}}
@if($member->users->isNotEmpty())
<div class="m-section">Uživatelé</div>
<div class="m-card" style="margin-bottom:16px">
    @foreach($member->users as $u)
    <div class="m-user-row">
        @php $ini = strtoupper(substr($u->name ?? 'U', 0, 1) . substr($u->surname ?? '', 0, 1)); @endphp
        <div class="m-avatar">{{ $ini }}</div>
        <div class="m-user-info">
            <div class="m-user-name">{{ $u->full_name }}</div>
            <div class="m-user-login">{{ $u->login }} — {{ $u->type == 1 ? 'Hlavní uživatel' : 'Uživatel' }}</div>
        </div>
        <div class="m-user-actions">
            <a class="m-link" href="{{ route('users.show', $u->id) }}">Detail</a>
        </div>
    </div>
    @endforeach
    <div style="margin-top:8px;">
        <a class="m-link" href="{{ route('users.create', ['member_id' => $member->id]) }}">+ Přidat uživatele</a>
    </div>
</div>
@endif

{{-- IP adresy --}}
@if($canViewIpAddresses && $member->ipAddresses->count() > 0)
<div class="m-section">IP adresy</div>
<div class="m-card" style="margin-bottom:16px">
    @foreach($member->ipAddresses as $ip)
    <div class="m-ip-row">
        <a class="m-ip-addr m-link" href="{{ route('ip_addresses.show', $ip->id) }}">{{ $ip->ip_address }}</a>
        <span style="font-size:12px;color:#888;">{{ $ip->subnet?->label ?? '—' }}</span>
        <span style="font-size:12px;color:#aaa;">
            {{ $ip->dhcp ? 'DHCP' : '' }}
            {{ $ip->gateway ? 'GW' : '' }}
        </span>
    </div>
    @endforeach
</div>
@endif

</div>
@endsection
