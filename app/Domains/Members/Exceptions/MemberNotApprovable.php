<?php

namespace App\Domains\Members\Exceptions;

/** Člena nelze schválit; zpráva je určená přímo pro uživatele (flash). */
class MemberNotApprovable extends \DomainException
{
    public static function notPending(): self
    {
        return new self('Člen není čekatel.');
    }

    public static function registrationNotSigned(): self
    {
        return new self('Přihláška/smlouva není podepsána.');
    }
}
