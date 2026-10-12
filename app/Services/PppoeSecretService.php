<?php

namespace App\Services;

use App\Domains\Access\Actions\AdoptPppoeCredentialAction;
use App\Domains\Access\Actions\EnsurePppoeCredentialAction;
use App\Domains\Access\Actions\RotatePppoeSecretAction;
use App\Domains\Access\Pppoe\PppoeCredentialGenerator;
use App\Models\Iface;
use App\Models\PppoeSecret;

/**
 * Fasáda pro zpětnou kompatibilitu — PPPoE credentialy jsou v doméně Access
 * (app/Domains/Access). Nový kód volá rovnou akce domény.
 * Model viz [[project_pppoe_wpa2_nis2]].
 */
class PppoeSecretService
{
    /** @see EnsurePppoeCredentialAction */
    public function ensureForIface(Iface $iface): ?PppoeSecret
    {
        return app(EnsurePppoeCredentialAction::class)->handle($iface);
    }

    /**
     * Sestaví (username, secret) pro člena BEZ persistence — pro žádost o
     * připojení; při schválení se překlopí přes {@see adoptFromRequest}.
     *
     * @return array{username: string, secret: string}
     */
    public function buildCredential(int $memberId): array
    {
        $generator = app(PppoeCredentialGenerator::class);
        return [
            'username' => $generator->usernameFor($memberId),
            'secret'   => $generator->secret(),
        ];
    }

    /** @see AdoptPppoeCredentialAction */
    public function adoptFromRequest(Iface $iface, string $username, string $secret): PppoeSecret
    {
        return app(AdoptPppoeCredentialAction::class)->handle($iface, $username, $secret);
    }

    /** @see RotatePppoeSecretAction */
    public function rotateSecret(Iface $iface): ?PppoeSecret
    {
        return app(RotatePppoeSecretAction::class)->handle($iface);
    }
}
