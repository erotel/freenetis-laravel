<?php

namespace App\Domains\Access\Actions;

use App\Domains\Access\Pppoe\PppoeCredentialGenerator;
use App\Domains\Access\Repositories\PppoeCredentialRepository;
use App\Models\Iface;
use App\Models\PppoeSecret;

/** Přegeneruje jen heslo (rotace), username zůstává. Bez credentialu ho založí. */
class RotatePppoeSecretAction
{
    public function __construct(
        private PppoeCredentialRepository $credentials,
        private PppoeCredentialGenerator $generator,
        private EnsurePppoeCredentialAction $ensure,
    ) {
    }

    public function handle(Iface $iface): ?PppoeSecret
    {
        $credential = $this->credentials->find($iface->id);
        if (!$credential) {
            return $this->ensure->handle($iface);
        }
        $this->credentials->update($credential, ['secret' => $this->generator->secret()]);
        return $credential;
    }
}
