@php
    $pwdLen   = \App\Rules\StrongPassword::minLength();
    $pwdLevel = max(1, min(4, (int) \App\Models\Setting::get('security_password_level', 3)));
    $pwdHelp  = [
        1 => 'cokoli kromě jednoho opakovaného znaku',
        2 => 'cokoli kromě jednoho opakovaného znaku',
        3 => 'dva druhy znaků: malá a velká písmena, nebo písmena a číslice',
        4 => 'malá i velká písmena a číslice, nebo písmena a aspoň 2 číslice, nebo speciální znak',
    ][$pwdLevel];
@endphp
<div class="m-form-hint" style="{{ $style ?? '' }}">Alespoň {{ $pwdLen }} znaků; {{ $pwdHelp }}. Heslo nesmí obsahovat přihlašovací jméno.</div>
