<?php

namespace App\Domains\Members\Exceptions;

/** Člena nelze trvale smazat (není čekatel ani bývalý). */
class MemberNotPurgeable extends \DomainException
{
}
