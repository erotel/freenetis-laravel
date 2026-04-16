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
<h2>{{ $member->typeLabel() }} {{ $member->name }}</h2>

@if(in_array($member->type, [1, 17, 18]))
<div style="background:#fff3cd; border:1px solid #ffc107; padding:8px 12px; margin-bottom:1em; border-radius:4px;">
    ⚠️ <strong>
        @if($member->type == 17) Čekající člen
        @elseif($member->type == 18) Čekající zákazník
        @else Žadatel
        @endif
    </strong> — přihláška/smlouva dosud nepodepsána.
    Člen nemá přístup k internetu ani mu nejsou strháváni poplatky.
    Po podpisu změňte typ přes <a href="{{ route('members.edit', $member->id) }}">Upravit</a>.
</div>
@endif

{{-- Section 1: Action links --}}
<div style="margin-bottom:1em;">
    @if($canEdit)
    <a href="{{ route('members.edit', $member->id) }}">
        <img src="{{ asset('media/images/icons/gtk_edit.png') }}" alt="Upravit">
        Upravit
    </a>
    @endif
    @if($creditAccount)
    &nbsp;|&nbsp;
    <a href="{{ route('accounts.show', $creditAccount->id) }}">Detail účtu</a>
    @endif
    @if($creditAccount && $canViewTransfers)
    &nbsp;|&nbsp;
    <a href="{{ route('transfers.by_account', $creditAccount->id) }}">Zobrazit převody účtu</a>
    @endif
    @if($canViewFees)
    &nbsp;|&nbsp;
    <a href="{{ route('members_fees.by_member', $member->id) }}">Zobrazit tarify</a>
    @endif
    @if($canViewAllowedSubnets)
    &nbsp;|&nbsp;
    <a href="{{ route('allowed_subnets.by_member', $member->id) }}">Povolené podsítě</a>
    @endif
    @if($canViewInvoices)
    &nbsp;|&nbsp;
    <a href="{{ route('invoices.by_member', $member->id) }}">Faktury</a>
    @endif
    @if($mainUser && ($canEditUser || auth()->id() == $mainUser->id))
    &nbsp;|&nbsp;
    <a href="{{ route('users.password', $mainUser->id) }}">Změnit heslo</a>
    @endif
    @if($canNotify)
    &nbsp;|&nbsp;
    <a href="{{ route('notifications.member', $member->id) }}">Oznámení</a>
    @endif
    @if($canViewWhitelists)
    &nbsp;|&nbsp;
    <a href="{{ route('member_whitelists.index', ['member_id' => $member->id]) }}">Bílé listiny</a>
    @endif
    @if($canEditRedirect)
    &nbsp;|&nbsp;
    <a href="{{ route('redirects.activate-member', $member->id) }}" style="color:#c60;">Přesměrovat</a>
    @endif
    @if($canExportRegistration && in_array($member->type, [2, 90]))
    &nbsp;|&nbsp;
    <form method="GET" style="display:inline;" id="export-form-{{ $member->id }}">
        <select name="_export_type" onchange="
            var t = this.value;
            if(t) window.open('{{ url('members/' . $member->id . '/registration-export') }}/' + t, '_blank');
            this.value='';
        " style="font-size:0.9em;">
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
    &nbsp;|&nbsp;
    <form method="POST" action="{{ route('members.approve', $member->id) }}" style="display:inline">
        @csrf
        <button type="submit"
                onclick="return confirm('Schválit čekatele a změnit typ na {{ $member->type == 17 ? "Řádný člen" : "Zákazník" }}?')"
                style="color:green; font-weight:bold;">✓ Schválit čekatele</button>
    </form>
    @endif
    @if($canDelete)
    &nbsp;|&nbsp;
    @if(in_array($member->type, [17, 18]))
        <form method="POST" action="{{ route('members.destroy', $member->id) }}" style="display:inline;">
            @csrf @method('DELETE')
            <button type="submit"
                    onclick="return confirm('Smazat čekajícího člena {{ addslashes($member->name) }}? Tato akce je nevratná!')"
                    style="color:red;">✕ Smazat</button>
        </form>
    @elseif(!in_array($member->type, [15, 16]))
        <a href="{{ route('members.end-membership', $member->id) }}"
           style="color:orange;">✕ Ukončit</a>
    @else
        <form method="POST" action="{{ route('members.destroy', $member->id) }}" style="display:inline;">
            @csrf @method('DELETE')
            <button type="submit"
                    onclick="return confirm('TRVALE smazat člena {{ addslashes($member->name) }} a veškerá jeho data? Tato akce je nevratná!')"
                    style="color:red; font-weight:bold;">✕ Trvale smazat</button>
        </form>
    @endif
    @if(in_array($member->type, [15, 16]))
    &nbsp;|&nbsp;
    <form method="POST" action="{{ route('members.restore', $member->id) }}" style="display:inline">
        @csrf
        <button type="submit"
                onclick="return confirm('Obnovit člena {{ addslashes($member->name) }}?')"
                style="color:green; font-weight:bold;">↩ Obnovit</button>
    </form>
    @endif
    @endif
</div>

{{-- Sections 2+3: Základní informace + Informace o účtu side by side --}}
<div style="overflow: hidden; margin-bottom: 20px;">

    {{-- Left: Základní informace --}}
    <table class="extended" cellspacing="0" style="float:left; width:360px; margin-right:20px;">
        <thead>
            <tr>
                <th colspan="2">Základní informace</th>
            </tr>
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
            @if($variableSymbols->isNotEmpty() || $creditAccount)
            <tr>
                <th>Variabilní symboly</th>
                <td>
                    {{ $variableSymbols->implode(', ') }}
                    @if($creditAccount)
                    @if($variableSymbols->isNotEmpty())
                    &nbsp;
                    @endif
                    <a href="{{ route('variable_symbols.by_account', $creditAccount->id) }}">Přidávání/editace</a>
                    @endif
                </td>
            </tr>
            @endif
            @if($member->entrance_date && $member->entrance_date !== '0000-00-00')
            <tr>
                <th>Datum vstupu</th>
                <td>{{ $member->entrance_date }}</td>
            </tr>
            @endif
            @if($member->leaving_date && $member->leaving_date !== '0000-00-00' && $member->leaving_date !== '9999-12-31')
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

            {{-- Address section --}}
            <tr>
                <th colspan="2" style="background:#e8e8e8;">Adresa</th>
            </tr>
            @if($member->addressPoint)
            @if($member->addressPoint->street)
            <tr>
                <th>Ulice</th>
                <td>{{ $member->addressPoint->street->street }}
                    {{ $member->addressPoint->street_number }}
                </td>
            </tr>
            @endif
            @if($member->addressPoint->town)
            <tr>
                <th>Město</th>
                <td>{{ $member->addressPoint->town->town }},
                    {{ $member->addressPoint->town->zip_code }}
                </td>
            </tr>
            @endif
            <tr>
                <th>Země</th>
                <td>Czech Republic</td>
            </tr>
            @else
            <tr>
                <td colspan="2">—</td>
            </tr>
            @endif

            {{-- Further info section --}}
            <tr>
                <th colspan="2" style="background:#e8e8e8;">Další informace</th>
            </tr>
            <tr>
                <th>{{ in_array($member->type, [2, 3, 16, 18]) ? 'Smlouva' : 'Přihláška' }}</th>
                <td>{{ $member->registration ? 'ano' : 'ne' }}</td>
            </tr>
            <tr>
                <th>Přístup do systému</th>
                <td>{{ $member->locked ? 'Zamčen' : 'Odemčen' }}</td>
            </tr>
            @if($member->comment)
            <tr>
                <th>Komentář</th>
                <td>{{ $member->comment }}</td>
            </tr>
            @endif
        </tbody>
    </table>

    {{-- Right: Informace o účtu --}}
    @if($creditAccount)
    <div style="float:left;">
        <table class="extended" cellspacing="0" style="width:280px;">
            <tr>
                <th colspan="2" style="background:#e8e8e8;">Informace o účtu</th>
            </tr>
            <tr>
                <th>Současný kredit</th>
                <td style="color:{{ $creditAccount->balance >= 0 ? 'green' : 'red' }}">
                    @if($accountComments)
                        <span title="{{ $accountComments }}" style="cursor:help; border-bottom:1px dotted #999;">
                            {{ number_format($creditAccount->balance, 2, ',', ' ') }} Kč
                        </span>
                    @else
                        {{ number_format($creditAccount->balance, 2, ',', ' ') }} Kč
                    @endif
                    @if($canComment)
                        @if($creditAccount->comments_thread_id)
                            <a href="{{ route('comments.add', $creditAccount->comments_thread_id) }}"
                               title="Přidat komentář k finančnímu stavu">
                                <img src="{{ asset('media/images/icons/comment_add.png') }}" width="16" height="16" alt="+">
                            </a>
                        @else
                            <a href="{{ route('comments.add-thread', ['type' => 'account', 'fkId' => $creditAccount->id]) }}"
                               title="Přidat komentář k finančnímu stavu">
                                <img src="{{ asset('media/images/icons/comment_add.png') }}" width="16" height="16" alt="+">
                            </a>
                        @endif
                    @endif
                </td>
            </tr>
            @if($accountCommentsList->count() && $canComment)
            <tr>
                <th>Komentáře k účtu</th>
                <td style="font-size:0.85em;">
                    @foreach($accountCommentsList as $ac)
                        <div style="margin-bottom:4px; border-bottom:1px solid #eee; padding-bottom:3px;">
                            <strong>{{ $ac->user_name }}</strong>
                            <small style="color:#888;">({{ \Carbon\Carbon::parse($ac->datetime)->format('d.m.Y') }})</small>:
                            {{ $ac->text }}
                            @if($canEditComment)
                                <a href="{{ route('comments.edit', $ac->id) }}" style="margin-left:4px;">Upravit</a>
                            @endif
                            @if($canDeleteComment)
                                <form method="POST" action="{{ route('comments.destroy', $ac->id) }}" style="display:inline;">
                                    @csrf @method('DELETE')
                                    <button type="submit" style="background:none;border:none;padding:0 4px;color:#c00;cursor:pointer;"
                                            onclick="return confirm('Smazat komentář?')">Smazat</button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </td>
            </tr>
            @endif
            <tr>
                <th>Měsíční platba</th>
                <td>
                    @if($activeMemberFee && $activeMemberFee->fee)
                        {{ number_format($activeMemberFee->fee->fee, 2, ',', ' ') }} Kč
                        @if(!App\Models\MemberFee::where('member_id', $member->id)->active()->exists())
                            <small style="color:#888;">(výchozí)</small>
                        @endif
                    @else
                        <span style="color:#888;">—</span>
                    @endif
                </td>
            </tr>
            @if($canViewQos && $member->speedClass)
            <tr>
                <th colspan="2" style="background:#e8e8e8; padding-top:6px;">QoS</th>
            </tr>
            <tr>
                <th>Třída</th>
                <td><a href="{{ route('speed_classes.index') }}">{{ $member->speedClass->name }}</a></td>
            </tr>
            <tr>
                <th>Max (D/U)</th>
                <td>{{ \App\Models\SpeedClass::formatPair($member->speedClass->d_ceil, $member->speedClass->u_ceil) }}</td>
            </tr>
            <tr>
                <th>Min (D/U)</th>
                <td>{{ \App\Models\SpeedClass::formatPair($member->speedClass->d_rate, $member->speedClass->u_rate) }}</td>
            </tr>
            @endif
        </table>
    </div>
    @endif


</div>
<div style="clear:both;"></div>

{{-- Section 4: Informace o hlavním uživateli --}}
@if($mainUser)
<h3>Informace o hlavním uživateli</h3>
<br>

@if($canViewUser)
<a href="{{ route('users.show', $mainUser->id) }}">Zobrazit</a>
@endif
@if($canEditUser)
| <a href="{{ route('users.edit', $mainUser->id) }}">Upravit</a>
@endif
@if($canViewDevices)
| <a href="{{ route('devices.by_user', $mainUser->id) }}">Zobrazit zařízení</a>
@endif
<br><br>

<div style="overflow: hidden;">
    <table class="extended" cellspacing="0" style="float:left; width:360px; margin-right:20px;">
        <tr>
            <th colspan="2" style="background:#e8e8e8;">Základní informace</th>
        </tr>
        <tr>
            <th>ID uživatele</th>
            <td>{{ $mainUser->id }}</td>
        </tr>
        <tr>
            <th>Přihl. jméno</th>
            <td>{{ $mainUser->login }}</td>
        </tr>
        <tr>
            <th>Jméno</th>
            <td>{{ $mainUser->full_name }}</td>
        </tr>
        @if($mainUser->birthday && $mainUser->birthday !== '0000-00-00')
        <tr>
            <th>Datum narození</th>
            <td>{{ $mainUser->birthday }}</td>
        </tr>
        @endif
        @if($mainUser->comment)
        <tr>
            <th>Komentář</th>
            <td>{{ $mainUser->comment }}</td>
        </tr>
        @endif
    </table>

    @if($canViewContacts && $contacts->count() > 0)
    <div style="float:left;">
        <table class="extended" cellspacing="0" style="width:300px;">
            <tr>
                <th colspan="2" style="background:#e8e8e8;">Kontaktní informace</th>
            </tr>
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
<div style="clear:both;"></div>
@endif

{{-- Section 5a: Aktivní přesměrování --}}
@if($canViewRedirect && $memberRedirections->isNotEmpty())
<h3>Aktivní přesměrování</h3>
<table class="extended" cellspacing="0">
    <thead>
        <tr>
            <th>IP adresa</th>
            <th>Zpráva</th>
            <th>Datum</th>
            <th>Komentář</th>
            @if($canDeleteRedirect) <th>Akce</th> @endif
        </tr>
    </thead>
    <tbody>
        @foreach($memberRedirections as $msgId => $group)
            @foreach($group as $r)
            <tr>
                <td>{{ $r->ip_address }}</td>
                <td>{{ $r->msg_name }}</td>
                <td>{{ \Carbon\Carbon::parse($r->datetime)->format('d.m.Y H:i') }}</td>
                <td>{{ $r->comment ?? '—' }}</td>
                @if($canDeleteRedirect)
                <td>
                    <form method="POST"
                          action="{{ route('redirects.delete', [$r->ip_address_id, $r->message_id]) }}"
                          style="display:inline">
                        @csrf @method('DELETE')
                        <button type="submit"
                                style="background:none;border:none;cursor:pointer;padding:0;color:#c00;text-decoration:underline;"
                                onclick="return confirm('Zrušit přesměrování {{ $r->ip_address }}?')">Zrušit</button>
                    </form>
                </td>
                @endif
            </tr>
            @endforeach
            {{-- Hromadné zrušení celé zprávy --}}
            @if($canDeleteRedirect && $group->count() > 1)
            <tr style="background:#fff8f0;">
                <td colspan="{{ $canDeleteRedirect ? 4 : 3 }}" style="text-align:right; padding-right:4px; color:#888; font-size:0.9em;">
                    Zpráva „{{ $group->first()->msg_name }}" — {{ $group->count() }} IP adres
                </td>
                <td>
                    <form method="POST"
                          action="{{ route('redirects.delete-member', [$member->id, $msgId]) }}"
                          style="display:inline">
                        @csrf @method('DELETE')
                        <button type="submit"
                                style="background:none;border:none;cursor:pointer;padding:0;color:#c00;text-decoration:underline;"
                                onclick="return confirm('Zrušit přesměrování pro všechny IP adresy člena?')">Zrušit vše</button>
                    </form>
                </td>
            </tr>
            @endif
        @endforeach
    </tbody>
</table>
@endif

{{-- Section 5b: Přerušení členství --}}
@if($canViewInterrupts && $interrupts->count() > 0)
<h3>Přerušení členství</h3>
<table class="extended" cellspacing="0">
    <thead>
        <tr>
            <th>ID</th>
            <th>Datum od</th>
            <th>Datum do</th>
            <th>Ukončit po přerušení</th>
            <th>Komentář</th>
            @if($canEditInterrupts) <th>Akce</th> @endif
        </tr>
    </thead>
    <tbody>
        @foreach($interrupts as $int)
        <tr>
            <td>{{ $int->id }}</td>
            <td>{{ $int->activation_date }}</td>
            <td>{{ $int->deactivation_date }}</td>
            <td>{{ $int->end_after_interrupt_end ? 'Ano' : 'Ne' }}</td>
            <td>{{ $int->comment }}</td>
            @if($canEditInterrupts)
            <td>
                <a href="{{ route('membership-interrupts.edit', $int->id) }}">Upravit</a>
                &nbsp;|&nbsp;
                <form method="POST" action="{{ route('membership-interrupts.destroy', $int->id) }}" style="display:inline">
                    @csrf @method('DELETE')
                    <button type="submit" style="background:none;border:none;padding:0;color:#c00;cursor:pointer;"
                            onclick="return confirm('Smazat přerušení #{{ $int->id }}?')">Smazat</button>
                </form>
            </td>
            @endif
        </tr>
        @endforeach
    </tbody>
</table>
@endif
@if($canEditInterrupts)
<p>
    <a href="{{ route('membership-interrupts.create', $member->id) }}">+ Přidat přerušení členství</a>
</p>
@endif

{{-- Section 5b: Uživatelé --}}
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

{{-- Section 6: IP adresy --}}
@if($canViewIpAddresses && $member->ipAddresses->count() > 0)
<h3>IP adresy</h3>
<table class="extended" cellspacing="0">
    <thead>
        <tr>
            <th>IP adresa</th>
            <th>Subnet</th>
            <th>DHCP</th>
            <th>Gateway</th>
        </tr>
    </thead>
    <tbody>
        @foreach($member->ipAddresses as $ip)
        <tr>
            <td>
                <a href="{{ route('ip_addresses.show', $ip->id) }}">{{ $ip->ip_address }}</a>
            </td>
            <td>{{ $ip->subnet?->label ?? '—' }}</td>
            <td>{{ $ip->dhcp ? '✓' : '—' }}</td>
            <td>{{ $ip->gateway ? '✓' : '—' }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endif
@endsection