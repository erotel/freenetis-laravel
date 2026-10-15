<?php

namespace App\Domains\Members\Support;

/** Způsob ukončení členství (formulář Ukončit). */
final class EndMode
{
    public const PLAIN       = 1; // bez e-mailu
    public const NOT_PAYING  = 2; // neplatil → e-mail 94
    public const REFUND      = 3; // s vratkou → e-mail 96 + dobropis
    public const OWN_REQUEST = 4; // na vlastní žádost → e-mail 34

    public const ALL = [self::PLAIN, self::NOT_PAYING, self::REFUND, self::OWN_REQUEST];
}
