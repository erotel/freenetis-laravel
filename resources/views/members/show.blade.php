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
<div class="member-page">

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
    @if($mainUser && $canViewDevices)
    <a class="m-btn" href="{{ route('devices.by_user', $mainUser->id) }}">Zařízení</a>
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
        @if($member->comment && $canViewComment)
        <div class="m-field"><span class="m-field-label">Komentář</span><span class="m-field-value">{{ $member->comment }}</span></div>
        @endif
        @if($tvEnabled)
        <div class="m-field">
            <span class="m-field-label">SledovaniTV</span>
            <span class="m-field-value">
                @if($member->tv_synced_at === null)
                    <span style="color:#999">— (nikdy synced)</span>
                @elseif($member->tv_active)
                    <span style="color:#27ae60">📺 Aktivní</span>
                    @if($member->tv_valid_until)
                        <span style="color:#888;font-size:14px">do {{ $member->tv_valid_until }}</span>
                    @endif
                @else
                    <span style="color:#c0392b">📺 Neaktivní</span>
                    @if($member->tv_valid_until)
                        <span style="color:#888;font-size:14px">(vypršelo {{ $member->tv_valid_until }})</span>
                    @endif
                @endif
            </span>
        </div>
        @endif
    </div>

    <div>
        <div class="m-card" style="margin-bottom:16px">
            <div class="m-card-title">Adresa</div>
            @if($member->addressPoint)
                @if($member->addressPoint->street || $member->addressPoint->street_number)
                <div class="m-field">
                    <span class="m-field-label">{{ $member->addressPoint->street ? 'Ulice' : 'Č. p.' }}</span>
                    <span class="m-field-value">{{ trim(($member->addressPoint->street?->street ?? '') . ' ' . ($member->addressPoint->street_number ?? '')) }}</span>
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
                <div style="font-size:16px;color:#aaa;padding:6px 0">—</div>
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
@if($creditAccount && $accountCommentsList->count() && $canViewComment)
<div class="m-section">Komentáře k účtu</div>
<div class="m-card" style="margin-bottom:16px">
    @foreach($accountCommentsList as $ac)
    <div class="m-field" style="align-items:flex-start; flex-direction:column; gap:2px;">
        <div style="font-size:14px;color:#888;">
            <strong>{{ $ac->user_name }}</strong>
            ({{ \Carbon\Carbon::parse($ac->datetime)->format('d.m.Y') }})
            @if($canEditComment) <a class="m-link" href="{{ route('comments.edit', $ac->id) }}">Upravit</a> @endif
            @if($canDeleteComment)
            <form method="POST" action="{{ route('comments.destroy', $ac->id) }}" style="display:inline;">
                @csrf @method('DELETE')
                <button type="submit" style="background:none;border:none;padding:0;color:#c00;cursor:pointer;font-size:14px;"
                        onclick="return confirm('Smazat komentář?')">Smazat</button>
            </form>
            @endif
        </div>
        <div style="font-size:16px;">{{ $ac->text }}</div>
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
            <span style="font-size:16px;color:#555;">{{ $r->msg_name }}</span>
            <span style="font-size:14px;color:#aaa;">{{ \Carbon\Carbon::parse($r->datetime)->format('d.m.Y H:i') }}</span>
            @if($canDeleteRedirect)
            <form method="POST" action="{{ route('redirects.delete', [$r->ip_address_id, $r->message_id]) }}" style="display:inline">
                @csrf @method('DELETE')
                <button type="submit" style="background:none;border:none;padding:0;color:#c00;cursor:pointer;font-size:14px;"
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
                <button type="submit" style="background:none;border:none;padding:0;color:#c00;cursor:pointer;font-size:14px;"
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
    @php $initials = mb_strtoupper(mb_substr($mainUser->name ?? 'U', 0, 1, 'UTF-8') . mb_substr($mainUser->surname ?? '', 0, 1, 'UTF-8'), 'UTF-8'); @endphp
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
        @if($mainUser->birthday)
        <div class="m-field">
            <span class="m-field-label">Datum narození</span>
            <span class="m-field-value">{{ \Carbon\Carbon::parse($mainUser->birthday)->format('d.m.Y') }}</span>
        </div>
        @endif
        <div style="margin-top:6px;">
            <a class="m-link" href="{{ route('contacts.show_by_user', $mainUser->id) }}">Přidávání/editace kontaktů</a>
        </div>
    </div>
    @elseif($mainUser->birthday)
    <div style="padding-top:8px">
        <div class="m-field">
            <span class="m-field-label">Datum narození</span>
            <span class="m-field-value">{{ \Carbon\Carbon::parse($mainUser->birthday)->format('d.m.Y') }}</span>
        </div>
    </div>
    @endif
</div>
@endif

{{-- Uživatelé --}}
@if($member->users->count() > 1)
<div class="m-section">Uživatelé</div>
<div class="m-card" style="margin-bottom:16px">
    @foreach($member->users as $u)
    <div class="m-user-row">
        @php $ini = mb_strtoupper(mb_substr($u->name ?? 'U', 0, 1, 'UTF-8') . mb_substr($u->surname ?? '', 0, 1, 'UTF-8'), 'UTF-8'); @endphp
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
@if(isset($gponOnts) && $gponOnts->count() > 0)
<div class="m-section">GPON ONT</div>
<div class="m-card" style="margin-bottom:16px">
    @foreach($gponOnts as $ont)
    <div class="m-field">
        <span class="m-field-label" style="font-family:monospace;font-size:14px">{{ $ont->serial }}</span>
        <span class="m-field-value" style="display:flex;align-items:center;gap:8px">
            <span class="m-tag m-tag-green">{{ $ont->gpon_port }}</span>
            <span style="font-size:14px;color:var(--fn-text-muted)">VLAN {{ $ont->vlan }}</span>
            <a class="m-link-sm" href="{{ route('gpon.show', $ont->id) }}">Detail</a>
        </span>
    </div>
    @endforeach
</div>
@endif

{{-- Smlouva --}}
@php
    $contract = $memberContract ?? null;
    $statusColors = [
        'draft'        => '#d97706',
        'otp_sent'     => '#d97706',
        'otp_verified' => '#2563eb',
        'signed'       => '#16a34a',
        'canceled'     => '#dc2626',
    ];
    $contractColor = $contract ? ($statusColors[$contract->status] ?? '#888') : '#888';
@endphp
<div class="m-section">Smlouva</div>
<div class="m-card" style="margin-bottom:16px">
@if($contract)
    <div class="m-field">
        <span class="m-field-label">Stav</span>
        <span class="m-field-value">
            <span style="display:inline-block;padding:2px 8px;border-radius:12px;font-size:14px;font-weight:600;background:{{ $contractColor }}1a;color:{{ $contractColor }};border:1px solid {{ $contractColor }}55">
                {{ $contract->statusLabel() }}
            </span>
        </span>
    </div>
    <div class="m-field">
        <span class="m-field-label">Číslo smlouvy</span>
        <span class="m-field-value" style="font-family:monospace">{{ $contract->contract_no }}</span>
    </div>
    @if($contract->signed_at)
    <div class="m-field">
        <span class="m-field-label">Datum podpisu</span>
        <span class="m-field-value">{{ $contract->signed_at->format('d.m.Y H:i') }}</span>
    </div>
    @endif
    <div class="m-field">
        <span class="m-field-label">Akce</span>
        <span class="m-field-value" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <a class="m-btn" href="{{ route('contracts.show', $member->id) }}">Detail smlouvy</a>
            @if($canEdit && in_array($contract->status, ['draft','otp_sent','otp_verified']))
            <form method="POST" action="{{ route('contracts.send-link', $member->id) }}" style="display:inline">
                @csrf
                <button type="submit" class="m-btn">Odeslat odkaz pro podpis</button>
            </form>
            @endif
            @if($contract->status === 'signed' && $contract->pdf_path)
            <a class="m-btn" href="{{ route('contracts.download', $contract->id) }}">Stáhnout PDF</a>
            @endif
        </span>
    </div>
    @if(session('sign_link'))
    <div style="margin-top:8px;padding:10px 12px;background:#fffbeb;border:1px solid #fcd34d;border-radius:6px;font-size:16px;">
        <strong>Podpisový odkaz:</strong><br>
        <a href="{{ session('sign_link') }}" target="_blank" rel="noopener" style="word-break:break-all">{{ session('sign_link') }}</a>
    </div>
    @endif
@else
    <div style="font-size:16px;color:#888;padding:4px 0">Žádná smlouva.</div>
    @if($canEdit)
    <div style="margin-top:10px">
        <form method="POST" action="{{ route('contracts.create', $member->id) }}" style="display:inline">
            @csrf
            <button type="submit" class="m-btn m-btn-success"
                    onclick="return confirm('Vytvořit novou smlouvu pro {{ addslashes($member->name) }}?')">
                + Vytvořit smlouvu
            </button>
        </form>
    </div>
    @endif
@endif
</div>

{{-- Dodatek --}}
@if($contract && $contract->status === 'signed')
@php $addonStatus = app(\App\Services\ContractService::class)->getAddonStatus($contract); @endphp
<div class="m-section">Dodatek ke smlouvě</div>
<div class="m-card" style="margin-bottom:16px">
    @if($addonStatus === 'none')
        <div style="font-size:16px;color:#888;padding:4px 0">Žádný dodatek.</div>
        @if($canEdit)
        <div style="margin-top:10px">
            <form method="POST" action="{{ route('contracts.addon.create', $member->id) }}" style="display:inline">
                @csrf
                <button type="submit" class="m-btn m-btn-success"
                        onclick="return confirm('Vytvořit dodatek ke smlouvě {{ addslashes($contract->contract_no) }}?')">
                    + Vytvořit dodatek
                </button>
            </form>
        </div>
        @endif
    @elseif($addonStatus === 'pending')
        <div class="m-field">
            <span class="m-field-label">Stav dodatku</span>
            <span class="m-field-value">
                <span style="display:inline-block;padding:2px 8px;border-radius:12px;font-size:14px;font-weight:600;background:#fef3c71a;color:#d97706;border:1px solid #fcd34d55">
                    Čeká na podpis
                </span>
            </span>
        </div>
        @if($canEdit)
        <div class="m-field">
            <span class="m-field-label">Akce</span>
            <span class="m-field-value" style="display:flex;gap:8px;flex-wrap:wrap">
                <form method="POST" action="{{ route('contracts.addon.send-link', $member->id) }}" style="display:inline">
                    @csrf
                    <button type="submit" class="m-btn">Odeslat odkaz pro podpis dodatku</button>
                </form>
                <form method="POST" action="{{ route('contracts.addon.delete', $contract->id) }}" style="display:inline">
                    @csrf @method('DELETE')
                    <button type="submit" class="m-btn m-btn-danger"
                            onclick="return confirm('Smazat nepodepsaný dodatek?')">Smazat dodatek</button>
                </form>
            </span>
        </div>
        @endif
        @if(session('addon_link'))
        <div style="margin-top:8px;padding:10px 12px;background:#fffbeb;border:1px solid #fcd34d;border-radius:6px;font-size:16px;">
            <strong>Odkaz pro podpis dodatku:</strong><br>
            <a href="{{ session('addon_link') }}" target="_blank" rel="noopener" style="word-break:break-all">{{ session('addon_link') }}</a>
        </div>
        @endif
    @else {{-- signed --}}
        <div class="m-field">
            <span class="m-field-label">Stav dodatku</span>
            <span class="m-field-value">
                <span style="display:inline-block;padding:2px 8px;border-radius:12px;font-size:14px;font-weight:600;background:#dcfce71a;color:#16a34a;border:1px solid #86efac55">
                    Podepsáno
                </span>
            </span>
        </div>
        @if($contract->addon_signed_at)
        <div class="m-field">
            <span class="m-field-label">Datum podpisu</span>
            <span class="m-field-value">{{ $contract->addon_signed_at->format('d.m.Y H:i') }}</span>
        </div>
        @endif
        @if($contract->addon_pdf_path)
        <div class="m-field">
            <span class="m-field-label">PDF</span>
            <span class="m-field-value">
                <a class="m-btn" href="{{ route('contracts.addon.download', $contract->id) }}">Stáhnout PDF dodatku</a>
            </span>
        </div>
        @endif
    @endif
</div>
@endif

@if($canViewIpAddresses && $member->ipAddresses->count() > 0)
<div class="m-section">IP adresy</div>
<div class="m-card" style="margin-bottom:16px">
    @foreach($member->ipAddresses as $ip)
    <div class="m-ip-row">
        <a class="m-ip-addr m-link" href="{{ route('ip_addresses.show', $ip->id) }}">{{ $ip->ip_address }}</a>
        <span style="font-size:14px;color:#888;">{{ $ip->subnet?->label ?? '—' }}</span>
        <span style="font-size:14px;color:#aaa;">
            {{ $ip->dhcp ? 'DHCP' : '' }}
            {{ $ip->gateway ? 'GW' : '' }}
        </span>
    </div>
    @endforeach
</div>
@endif

</div>
@endsection
