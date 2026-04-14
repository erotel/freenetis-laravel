<?php
/**
 * PDF view for registration (Přihláška) and end-of-membership (Ukončení).
 * Variables: $type, $member, $assoc, $mainUser, $email, $phone,
 *            $variableSymbols, $bankAccount, $logoPath,
 *            $registrationInfo, $registrationLicense
 */

$isRegistration = ($type === 'registration');

$title = $isRegistration
    ? 'Žádost o členství – registrace v sdružení'
    : 'Žádost o ukončení členství';

// Format address helper
$memberAddress = trim(($member->street ?? '') . ' ' . ($member->street_number ?? ''));
$assocAddress  = trim(($assoc->street  ?? '') . ' ' . ($assoc->street_number  ?? ''));

$today = now()->format('d.m.Y');

$entranceDate = $member->entrance_date && $member->entrance_date !== '0000-00-00'
    ? \Carbon\Carbon::parse($member->entrance_date)->format('d.m.Y')
    : '';
$leavingDate  = $member->leaving_date && !in_array($member->leaving_date, ['0000-00-00', '9999-12-31'])
    ? \Carbon\Carbon::parse($member->leaving_date)->format('d.m.Y')
    : '';
$birthday = $mainUser && $mainUser->birthday && $mainUser->birthday !== '0000-00-00'
    ? \Carbon\Carbon::parse($mainUser->birthday)->format('d.m.Y')
    : '';
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    body        { font-family: dejavusans, sans-serif; font-size: 9pt; color: #000; }
    h1          { font-size: 13pt; text-align: center; margin-bottom: 4px; }
    h2          { font-size: 10pt; margin: 12px 0 4px 0; border-bottom: 1px solid #999; padding-bottom:2px; }
    table.info  { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
    table.info th { width: 35%; text-align: left; padding: 3px 6px; background: #f0f0f0; font-weight: bold; vertical-align: top; }
    table.info td { padding: 3px 6px; vertical-align: top; }
    table.info tr:nth-child(even) th { background: #e8e8e8; }
    .logo       { text-align: center; margin-bottom: 10px; }
    .assoc-header { text-align: center; font-size: 8pt; color: #555; margin-bottom: 8px; }
    .info-text  { font-size: 8pt; margin-top: 10px; border: 1px solid #ccc; padding: 6px; }
    .license    { font-size: 7.5pt; margin-top: 8px; border: 1px solid #ccc; padding: 6px; }
    .signature  { margin-top: 30px; }
    .sig-row    { overflow: hidden; margin-top: 20px; }
    .sig-left   { float: left; width: 45%; border-top: 1px solid #000; padding-top: 4px; font-size: 8pt; text-align: center; }
    .sig-right  { float: right; width: 45%; border-top: 1px solid #000; padding-top: 4px; font-size: 8pt; text-align: center; }
    .date-line  { text-align: right; margin-bottom: 10px; font-size: 8pt; }
</style>
</head>
<body>

{{-- Logo --}}
@if($logoPath && file_exists($logoPath))
<div class="logo">
    <img src="{{ $logoPath }}" style="max-height:60px; max-width:200px;">
</div>
@endif

{{-- Association header --}}
<div class="assoc-header">
    <strong>{{ $assoc->name }}</strong><br>
    {{ $assocAddress }}@if($assocAddress && ($assoc->zip_code || $assoc->town)), @endif
    @if($assoc->zip_code){{ $assoc->zip_code }} @endif{{ $assoc->town }}<br>
    @if($assoc->organization_identifier)IČO: {{ $assoc->organization_identifier }}@endif
    @if($assoc->vat_organization_identifier) &nbsp;|&nbsp; DIČ: {{ $assoc->vat_organization_identifier }}@endif
    @if($bankAccount) &nbsp;|&nbsp; č. účtu: {{ $bankAccount->account_nr }}/{{ $bankAccount->bank_nr }}@endif
</div>

<h1>{{ $title }}</h1>
<div class="date-line">Datum: {{ $today }}</div>

{{-- Member info --}}
<h2>Údaje žadatele</h2>
<table class="info">
    <tr>
        <th>Jméno</th>
        <td>
            @if($mainUser)
                {{ $mainUser->name }}{{ $mainUser->surname ? ' ' . $mainUser->surname : '' }}
            @else
                {{ $member->name }}
            @endif
        </td>
    </tr>
    @if($birthday)
    <tr>
        <th>Datum narození</th>
        <td>{{ $birthday }}</td>
    </tr>
    @endif
    <tr>
        <th>Adresa</th>
        <td>
            {{ $memberAddress }}@if($memberAddress && ($member->zip_code || $member->town)), @endif
            @if($member->zip_code){{ $member->zip_code }} @endif{{ $member->town }}
        </td>
    </tr>
    @if($email)
    <tr>
        <th>E-mail</th>
        <td>{{ $email }}</td>
    </tr>
    @endif
    @if($phone)
    <tr>
        <th>Telefon</th>
        <td>{{ $phone }}</td>
    </tr>
    @endif
    @if($member->organization_identifier)
    <tr>
        <th>IČO</th>
        <td>{{ $member->organization_identifier }}</td>
    </tr>
    @endif
    @if($variableSymbols->count())
    <tr>
        <th>Variabilní symbol</th>
        <td>{{ $variableSymbols->implode(', ') }}</td>
    </tr>
    @endif
    @if($isRegistration && $entranceDate)
    <tr>
        <th>Datum vstupu</th>
        <td>{{ $entranceDate }}</td>
    </tr>
    @endif
    @if(!$isRegistration && $leavingDate)
    <tr>
        <th>Datum ukončení</th>
        <td>{{ $leavingDate }}</td>
    </tr>
    @endif
</table>

{{-- Extra info / license for registration only --}}
@if($isRegistration)
    @if($registrationInfo)
    <div class="info-text">{{ $registrationInfo }}</div>
    @endif
    @if($registrationLicense)
    <div class="license">{{ $registrationLicense }}</div>
    @endif
@endif

{{-- Signatures --}}
<div class="signature">
    <div class="sig-row">
        <div class="sig-left">Podpis žadatele</div>
        <div class="sig-right">Razítko a podpis sdružení</div>
    </div>
</div>

</body>
</html>
