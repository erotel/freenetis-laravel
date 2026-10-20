<?php

namespace Tests\Feature\Security;

use App\Support\AclRouteCoverage;
use Tests\TestCase;

/**
 * Pojistka autorizace (NIS2/ZoKB): žádná NOVÁ přihlášená routa nesmí projít bez
 * autorizace. Když se objeví, tenhle test spadne a vynutí rozhodnutí —
 * buď ji ošetřit (aclCheck / acl middleware), nebo (pokud je záměrně
 * self-service / ownership / referenční) přidat do AclRouteCoverage::ALLOWED
 * s odůvodněním.
 */
class AclCoverageTest extends TestCase
{
    public function test_zadna_neocekavana_neochranovana_routa(): void
    {
        $unexpected = array_map(fn ($g) => $g['methods'] . ' ' . $g['uri'] . ' → ' . $g['action'], (new AclRouteCoverage())->scan()['gaps']);

        $this->assertSame(
            [],
            $unexpected,
            "Nová přihlášená routa bez autorizace. Přidej aclCheck/abort v controlleru "
            . "nebo acl: middleware; pokud je to záměr (self-service/ownership/ref. data), "
            . "přidej ji do AclRouteCoverage::ALLOWED s odůvodněním."
        );
    }

    /** Výjimka, která už neodpovídá žádné routě, se má ze seznamu smazat. */
    public function test_zadna_mrtva_vyjimka(): void
    {
        $used = array_column((new AclRouteCoverage())->scan()['allowed'], 'action');
        $this->assertSame([], array_values(array_diff(array_keys(AclRouteCoverage::ALLOWED), $used)));
    }
}
