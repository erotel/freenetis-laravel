<?php

namespace App\Domains\Network\Exceptions;

/** Zařízení nelze přiřadit členovi; zpráva patří k poli member_id formuláře. */
class DeviceOwnerInvalid extends \DomainException
{
    public static function noMainUser(): self
    {
        return new self('Zvolený člen nemá hlavního uživatele.');
    }
}
