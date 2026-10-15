<?php

namespace App\Domains\Members\Exceptions;

/** Člena nelze obnovit; zpráva je určená přímo pro uživatele (flash). */
class MemberNotRestorable extends \DomainException
{
    public static function notFormer(): self
    {
        return new self('Tento člen není označen jako bývalý.');
    }

    public static function notInterrupted(): self
    {
        return new self('Tento člen nemá aktivní přerušení členství.');
    }
}
