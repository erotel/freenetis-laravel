<?php

namespace App\Domains\Network\Exceptions;

/** IP rozhraní odmítnuta; field = pole formuláře, zpráva pro uživatele. */
class IfaceIpRejected extends \DomainException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
