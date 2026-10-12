<?php

namespace App\Domains\Access\Actions;

use App\Domains\Access\Repositories\PppoeCredentialRepository;
use App\Models\Iface;
use App\Models\PppoeSecret;

/**
 * Při schválení žádosti o připojení překlopí credential z žádosti na vzniklou
 * přípojku. Zachová username/heslo, které instalátor už zadal do CPE.
 * Idempotentní přes iface_id.
 */
class AdoptPppoeCredentialAction
{
    public function __construct(private PppoeCredentialRepository $credentials)
    {
    }

    public function handle(Iface $iface, string $username, string $secret): PppoeSecret
    {
        return $this->credentials->upsertEnabled($iface->id, $username, $secret);
    }
}
