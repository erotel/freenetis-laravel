<?php

namespace Tests\Support;

/**
 * Testovací hesla, tokeny a tajemství se generují za běhu (deterministicky
 * podle štítku) — ve zdrojácích tak není nic, co by skenery tajemství
 * (GitGuardian u veřejného repozitáře) hlásily jako uniklé heslo.
 * Výsledek je „silné" heslo (velké i malé písmeno, číslice, speciální znak).
 */
final class Fake
{
    public static function secret(string $label): string
    {
        return 'Tst' . substr(hash('sha256', 'freenetis-test:' . $label), 0, 12) . '-9';
    }
}
