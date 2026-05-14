<?php
/**
 * PDF: Přihláška (registration) + Ukončení členství (end)
 * Layout dle vzorových PDF — PVfree.net
 *
 * Variables: $type, $member, $assoc, $mainUser,
 *            $email, $phone, $variableSymbols, $bankAccount,
 *            $registrationInfo, $registrationLicense,
 *            $otherContacts, $creditBalance, $subnetName, $engineers
 */

$isRegistration = ($type === 'registration');

// Assoc header
$assocStreet = trim(($assoc->street ?? '') . ' ' . ($assoc->street_number ?? ''));
$assocCity   = trim(($assoc->zip_code ?? '') . ' ' . ($assoc->town ?? ''));
if ($assoc->quarter ?? '') {
    $assocCity = trim(($assoc->zip_code ?? '') . ' ' . ($assoc->town ?? '') . ($assoc->quarter ? ' ' . $assoc->quarter : ''));
}
$bankNr = $bankAccount ? ($bankAccount->account_nr . '/' . $bankAccount->bank_nr) : '';

// Member data
$memberName = $member->name ?? '';
$memberId   = $member->id;

$memberStreet = trim(($member->street ?? '') . ' ' . ($member->street_number ?? ''));
$memberCity   = ($member->zip_code ?? '') . ' ' . ($member->town ?? '');

$birthday = $mainUser && !empty($mainUser->birthday) && $mainUser->birthday !== '0000-00-00'
    ? \Carbon\Carbon::parse($mainUser->birthday)->format('j. n. Y')
    : '';

$entranceDate = !empty($member->entrance_date) && $member->entrance_date !== '0000-00-00'
    ? \Carbon\Carbon::parse($member->entrance_date)->format('j. n. Y')
    : '';

$leavingDate = !empty($member->leaving_date)
    && !in_array($member->leaving_date, ['0000-00-00', '9999-12-31'])
    ? \Carbon\Carbon::parse($member->leaving_date)->format('j. n. Y')
    : '';

$vsStr      = $variableSymbols->implode(', ');
$engineerStr = $engineers->implode(', ');

// ICQ/Jabber/Skype as "Type: value" pairs
$otherContactStr = $otherContacts->map(fn($c) => $c->type_name . ': ' . $c->value)->implode(', ');

$today = now()->format('d.m.Y');

// Formatted credit
$credit = $creditBalance ? number_format((float)$creditBalance, 2, ',', ' ') . ' Kč' : '';
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    body        { font-family: dejavusans, sans-serif; font-size: 10pt; color: #000; margin: 0; }
    /* Header block top-right */
    .hdr-wrap   { text-align: right; margin-bottom: 16px; font-size: 10pt; line-height: 1.5; }
    .hdr-wrap b { font-weight: bold; }
    .hdr-tbl    { display: inline-table; text-align: left; }
    .hdr-tbl td { padding: 0 4px 0 0; white-space: nowrap; }
    /* Title */
    h1          { font-size: 17pt; font-weight: bold; font-style: italic; text-align: center;
                  margin: 0 0 14px 0; }
    /* Intro text */
    .intro      { font-size: 8.5pt; text-align: justify; margin-bottom: 10px; line-height: 1.35; }
    /* Data table */
    table.data  { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
    table.data td { border: 1px solid #000; padding: 5px 7px; vertical-align: middle; }
    table.data td.lbl  { font-weight: bold; font-size: 9pt; width: 22%; }
    table.data td.val  { text-align: center; font-size: 9.5pt; width: 28%; }
    table.data td.lbl2 { font-weight: bold; font-size: 9pt; width: 22%; }
    table.data td.val2 { text-align: center; font-size: 9.5pt; width: 28%; }
    /* Warning */
    .warning    { font-size: 9pt; font-weight: bold; color: #c00; text-decoration: underline;
                  margin: 10px 0 8px 0; }
    /* Small info texts */
    .small-info { font-size: 8pt; margin-bottom: 6px; line-height: 1.35; }
    /* Signature lines */
    .sig-center { text-align: center; font-size: 11pt; font-weight: bold; margin: 30px 0 8px 0; }
    .sig-left   { font-size: 11pt; font-weight: bold; margin: 16px 0 4px 0; }
    .sig-section-title { font-size: 14pt; font-weight: bold; text-align: center;
                         margin: 28px 0 14px 0; }
    /* Refund line */
    .refund-line { font-weight: bold; font-size: 10pt; margin: 16px 0 10px 0; }
    .date-line   { font-weight: bold; font-size: 10pt; margin: 16px 0; }
</style>
</head>
<body>

{{-- ── Header (top right) ── --}}
<div class="hdr-wrap">
    <table class="hdr-tbl">
        <tr><td colspan="2"><b>{{ $assoc->name }}</b></td></tr>
        @if($assoc->organization_identifier)
        <tr>
            <td>IČ:</td>
            <td><b>{{ $assoc->organization_identifier }}</b></td>
        </tr>
        @endif
        @if($assoc->vat_organization_identifier)
        <tr>
            <td>DIČ:</td>
            <td><b>{{ $assoc->vat_organization_identifier }}</b></td>
        </tr>
        @endif
        @if($bankNr)
        <tr>
            <td>Číslo účtu:</td>
            <td><b>{{ $bankNr }}</b></td>
        </tr>
        @endif
        @if($assocStreet)
        <tr><td colspan="2"><b>{{ $assocStreet }}</b></td></tr>
        @endif
        @if($assocCity)
        <tr><td colspan="2"><b>{{ $assocCity }}</b></td></tr>
        @endif
    </table>
</div>

{{-- ── Title ── --}}
@if($isRegistration)
    <h1>Žádost o členství – registrace ve spolku</h1>
@else
    <h1>Žádost o ukončení členství v {{ $assoc->name }}</h1>
@endif

{{-- ── Intro text (registration only) ── --}}
@if($isRegistration)
    @if($registrationInfo)
        <div class="intro">{!! $registrationInfo !!}</div>
    @else
        <p class="intro">
            Žadatel se tímto stává čekatelem na členství v {{ $assoc->name }} se sídlem v {{ $assoc->town }},
            {{ $assoc->street }} ve smyslu ustanovení čl. 3 Stanov zapsaného spolku {{ $assoc->name }}
            se sídlem: {{ $assoc->street }}, {{ $assoc->town }}, {{ $assoc->zip_code }},
            IČO: {{ wordwrap($assoc->organization_identifier ?? '', 3, ' ', true) }}
            (dále jen "Spolek") a současně svobodně a dobrovolně žádá o přijetí do Spolku
            a o zápis do seznamu členů.
        </p>
        <p class="intro">
            Čekatel se podpisem této přihlášky zavazuje, že v případě vzniku jeho členství ve Spolku
            bude uplatňovat členská práva a plnit členské povinnosti, nebude jakkoliv porušovat
            stanovy ani vnitřní předpisy.
        </p>
    @endif
@endif

{{-- ── Data table ── --}}
<table class="data">
    <tr>
        <td class="lbl">Jméno,<br>Příjmení, Titul</td>
        <td class="val">{{ $memberName }}</td>
        <td class="lbl2">ID člena<br>(podle<br>freenetisu)</td>
        <td class="val2"><b>{{ $memberId }}</b></td>
    </tr>
    <tr>
        <td class="lbl"><b>Adresa přípojného<br>místa</b> (Ulice, ČP.,<br>Město, PSČ)</td>
        <td class="val">{{ $memberStreet }}<br>{{ $member->town }}<br>{{ $member->zip_code }}</td>
        <td class="lbl2">E-mail</td>
        <td class="val2">{{ $email }}</td>
    </tr>
    <tr>
        <td class="lbl">Datum narození</td>
        <td class="val">{{ $birthday }}</td>
        <td class="lbl2"><b>Telefon</b></td>
        <td class="val2">{{ $phone }}</td>
    </tr>
    <tr>
        <td class="lbl">Variabilní symbol</td>
        <td class="val">{{ $vsStr }}</td>
        @if($isRegistration)
            <td class="lbl2">ICQ, Jabber,<br>Skype, apod.</td>
            <td class="val2">{{ $otherContactStr }}</td>
        @else
            <td class="lbl2"><b>Kredit</b></td>
            <td class="val2">{{ $credit }}</td>
        @endif
    </tr>
    @if($isRegistration)
    <tr>
        <td class="lbl"><b>Podsíť</b></td>
        <td class="val">{{ $subnetName }}</td>
        <td class="lbl2">Technik</td>
        <td class="val2">{{ $engineerStr }}</td>
    </tr>
    @endif
    <tr>
        <td class="lbl">Datum vstupu</td>
        <td class="val">{{ $entranceDate }}</td>
        @if(!$isRegistration)
            <td class="lbl2">Datum ukončení</td>
            <td class="val2">{{ $leavingDate }}</td>
        @else
            <td class="lbl2"></td>
            <td class="val2"></td>
        @endif
    </tr>
</table>

@if($isRegistration)
    {{-- ── Warning ── --}}
    @if($registrationLicense)
        <div class="intro">{!! $registrationLicense !!}</div>
    @else
        <p class="warning">
            POZOR! Registrací se nerozumí předání žádosti o technické řešení připojení.
            Po registraci požádejte znalou osobu o technickou realizaci připojení k síti
            {{ $assoc->name }}, nebo <span style="text-decoration:underline">kontaktujte spolehlivé a prověřené techniky.</span>
        </p>
        <p class="small-info">
            Svým podpisem potvrzuji, že jsem se seznámil(a) s informacemi o zpracování osobních
            údajů ze strany spolku. Beru na vědomí, že podrobné informace o mých právech a
            povinnostech jsou k dispozici na www.pvfree.net, případně na vyžádání v sídle spolku.
        </p>
        @if($assocEmail || $assocPhone || $assocWww)
        <p class="small-info">
            @if($assocEmail){{ $assocEmail }} - správa sítě, připojování členů, pomoc s nastavením zařízení, technická podpora,<br>@endif
            @if($assocPhone)Telefonická infolinka {{ $assocPhone }} funguje v pracovní dny.@endif
        </p>
        @endif
    @endif

    {{-- ── Signature ── --}}
    <div class="sig-center">
        podpis žadatele o členství : ........................................
    </div>

    {{-- ── Council decision ── --}}
    <div class="sig-section-title">Rozhodnutí Rady o přijetí člena</div>
    <div class="sig-left">Člen přijat ke dni: .........................................</div>
    <br>
    <div class="sig-left">Podpis a razítko: .........................................</div>

@else
    {{-- ── Refund request ── --}}
    <p class="refund-line">
        Žádám o vrácení přeplatku na č.ú.: :
        &#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;
        /&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;
    </p>

    <p class="date-line">Datum : {{ $today }}</p>
    <br><br>
    <p class="sig-left">Podpis : .........................................</p>
@endif

</body>
</html>
