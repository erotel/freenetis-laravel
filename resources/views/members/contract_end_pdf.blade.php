<?php
/**
 * PDF: Výpověď smlouvy o poskytování služeb elektronických komunikací
 * Dle vzoru — CUSTOMER (type=2)
 *
 * Variables: $member, $assoc, $mainUser, $email, $phone,
 *            $variableSymbols, $bankAccount,
 *            $assocWww, $assocEmail, $assocPhone,
 *            $assocCourt, $assocCourtRef
 */

$assocAddress = trim(($assoc->street ?? '') . ' ' . ($assoc->street_number ?? ''));

// Vždy z members.name — zákonný požadavek (platí pro fyzické i právnické osoby)
$memberFullName = $member->name ?? '';

$memberAddress = trim(($member->street ?? '') . ' ' . ($member->street_number ?? ''))
    . ', ' . ($member->zip_code ?? '') . ' ' . ($member->town ?? '');

$birthday = $mainUser && !empty($mainUser->birthday) && $mainUser->birthday !== '0000-00-00'
    ? \Carbon\Carbon::parse($mainUser->birthday)->format('d.m.Y')
    : '';

// Datum nar. / IČO / DIČ — show whichever is available
$birthdayOrIco = $birthday;
if (!$birthdayOrIco && $member->organization_identifier) {
    $birthdayOrIco = 'IČO: ' . $member->organization_identifier;
    if ($member->vat_organization_identifier) {
        $birthdayOrIco .= ', DIČ: ' . $member->vat_organization_identifier;
    }
}

$today = now()->format('d.m.Y');

// Build provider identification string
$assocIdent = '<b>' . e($assoc->name) . '</b>, se sídlem '
    . e($assocAddress) . ', ' . e($assoc->zip_code) . ' ' . e($assoc->town);
if ($assoc->organization_identifier) {
    $assocIdent .= ', IČO: ' . e($assoc->organization_identifier);
}
if ($assoc->vat_organization_identifier) {
    $assocIdent .= ', DIČ: ' . e($assoc->vat_organization_identifier);
}
if ($assocCourt && $assocCourtRef) {
    $assocIdent .= ' zapsán ve spolkovém rejstříku vedeném u ' . e($assocCourt)
        . ', spisová značka ' . e($assocCourtRef);
} elseif ($assocCourt) {
    $assocIdent .= ' zapsán ve spolkovém rejstříku vedeném u ' . e($assocCourt);
}
if ($assocWww) {
    $assocIdent .= ', ' . e($assocWww);
}
$assocIdent .= ' (dále jen „<b>Poskytovatel</b>")';
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    body        { font-family: dejavusans, sans-serif; font-size: 10pt; color: #000; margin: 0; }
    h1          { font-size: 13pt; font-weight: bold; text-align: center; margin: 0 0 14px 0; text-transform: uppercase; }
    p           { margin: 6px 0; line-height: 1.4; }
    table.info  { width: 100%; border-collapse: collapse; margin: 10px 0; }
    table.info td { border: 1px solid #000; padding: 5px 8px; }
    table.info td.lbl { width: 38%; }
    table.info td.val { width: 62%; }
    ul.bullets  { margin: 4px 0 4px 20px; padding: 0; }
    ul.bullets li { margin-bottom: 3px; }
    .footer     { position: fixed; bottom: 10px; left: 0; right: 0;
                  text-align: center; font-size: 9pt; color: #444;
                  border-top: 1px solid #ccc; padding-top: 4px; }
    .dots       { display: inline; }
</style>
</head>
<body>

<h1>Výpověď smlouvy o poskytování služeb elektronických komunikací</h1>

{{-- Identifikace poskytovatele --}}
<p>{!! $assocIdent !!}</p>

<p>a</p>

{{-- Tabulka účastníka --}}
<table class="info">
    <tr>
        <td class="lbl">Jméno a příjmení (obchodní název):</td>
        <td class="val">{{ $memberFullName }}</td>
    </tr>
    <tr>
        <td class="lbl">Datum narození (IČO, DIČ):</td>
        <td class="val">{{ $birthdayOrIco }}</td>
    </tr>
    <tr>
        <td class="lbl">Email:</td>
        <td class="val">{{ $email }}</td>
    </tr>
    <tr>
        <td class="lbl">Telefon:</td>
        <td class="val">{{ $phone }}</td>
    </tr>
    <tr>
        <td class="lbl">Adresa (sídlo společnosti):</td>
        <td class="val">{{ $memberAddress }}</td>
    </tr>
    <tr>
        <td class="lbl">Variabilní symbol:</td>
        <td class="val">{{ $variableSymbols->implode(', ') }}</td>
    </tr>
</table>

<p>(dále jen „<b>Účastník</b>")</p>
<p>(Poskytovatel a Účastník dále také jen „<b>Smluvní strany</b>")</p>

<br>

<p>
    Žádám o ukončení smlouvy ke dni :
    <span class="dots">&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;</span>
    ,&nbsp;přičemž jsem si vědom(a), že smlouva bude ukončena nejdříve po uplynutí výpovědní lhůty 14 dní dle VOP.
</p>

<p>Žádám o :</p>
<ul class="bullets">
    <li>
        vrácení případného přeplatku na účet :
        &#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;&#160;
    </li>
    <li>potvrzení přijetí této výpovědi na email</li>
    <li>sdělení konkrétního data ukončení poskytování služeb</li>
</ul>

<br><br><br><br>

<p>Dne:&#160;&#160;{{ $today }}</p>

<br><br><br>

<p><b>Účastník:</b></p>

{{-- Footer --}}
@if($assocWww || $assocEmail || $assocPhone)
<div class="footer">
    @if($assocWww)<span>{{ $assocWww }}</span>@endif
    @if($assocEmail)&#160;&#160;&#160;<span>{{ $assocEmail }}</span>@endif
    @if($assocPhone)&#160;&#160;&#160;<span>{{ $assocPhone }}</span>@endif
</div>
@endif

</body>
</html>
