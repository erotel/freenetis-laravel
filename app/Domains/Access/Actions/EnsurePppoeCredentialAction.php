<?php

namespace App\Domains\Access\Actions;

use App\Domains\Access\Pppoe\PppoeCredentialGenerator;
use App\Domains\Access\Repositories\PppoeCredentialRepository;
use App\Models\Iface;
use App\Models\PppoeSecret;
use Illuminate\Support\Facades\Log;

/**
 * Zajistí PPPoE credential pro přípojku. Idempotentní: existující heslo NEMĚNÍ
 * (nesmí se rozejít s tím, co má zákazník v CPE), jen dorovná username, kdyby
 * se změnil VS. Nový → vygeneruje heslo. Bez člena → null.
 */
class EnsurePppoeCredentialAction
{
    public function __construct(
        private PppoeCredentialRepository $credentials,
        private PppoeCredentialGenerator $generator,
    ) {
    }

    public function handle(Iface $iface): ?PppoeSecret
    {
        $memberId = $this->credentials->memberIdForIface($iface);
        if ($memberId === null) {
            Log::warning('PppoeSecret: iface bez člena, credential nevytvořen', ['iface' => $iface->id]);
            return null;
        }

        $existing = $this->credentials->find($iface->id);
        $username = $this->generator->usernameFor($memberId, $iface->id);

        if ($existing) {
            if ($existing->username !== $username) {
                $this->credentials->update($existing, ['username' => $username]);
            }
            return $existing;
        }

        return $this->credentials->create($iface->id, $username, $this->generator->secret());
    }
}
