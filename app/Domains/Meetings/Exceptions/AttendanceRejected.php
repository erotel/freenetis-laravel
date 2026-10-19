<?php

namespace App\Domains\Meetings\Exceptions;

/** Změna docházky odmítnuta; field = klíč chyby ve formuláři, zpráva pro uživatele. */
class AttendanceRejected extends \DomainException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
