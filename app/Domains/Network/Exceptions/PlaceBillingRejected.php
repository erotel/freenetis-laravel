<?php

namespace App\Domains\Network\Exceptions;

/** Účtování přípojného místa nejde zapnout; $viaContract = musí přes dodatek ke smlouvě. */
class PlaceBillingRejected extends \DomainException
{
    public function __construct(string $message, public readonly bool $viaContract = false)
    {
        parent::__construct($message);
    }
}
