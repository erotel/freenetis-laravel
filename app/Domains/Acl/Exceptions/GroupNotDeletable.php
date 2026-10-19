<?php

namespace App\Domains\Acl\Exceptions;

/** Skupinu nelze smazat (má podskupiny / uživatele); zpráva je pro uživatele. */
class GroupNotDeletable extends \DomainException
{
}
