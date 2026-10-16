<?php

namespace App\Domains\Contracts\Exceptions;

/** Selhalo vytvoření/uložení podepsaného PDF (transakce vrácena); zpráva = detail původní chyby. */
class ContractSigningFailed extends \RuntimeException
{
    public static function from(\Throwable $e): self
    {
        return new self($e->getMessage(), 0, $e);
    }
}
