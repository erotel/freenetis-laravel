<?php

namespace Tests\Feature\Security;

use App\Support\AclRouteCoverage;
use Tests\TestCase;

/**
 * Pojistka autorizace (NIS2/ZoKB): žádná NOVÁ přihlášená routa nesmí projít bez
 * autorizace. Když se objeví, tenhle test spadne a vynutí rozhodnutí —
 * buď ji ošetřit (aclCheck / acl middleware), nebo (pokud je záměrně
 * self-service / ownership / referenční) přidat do allowlistu níže s odůvodněním.
 */
class AclCoverageTest extends TestCase
{
    /**
     * Routy bez ACL, které jsou VĚDOMĚ v pořádku (posouzeno 2026-08-13):
     * self-service (vlastní účet), ownership-scoped, nebo necitlivá ref. data.
     */
    private const ALLOWED = [
        'LoginController@logout',                 // odhlášení sebe sama
        'WebAuthnController@index',               // vlastní passkeys (where user_id = Auth::id)
        'WebAuthnController@destroy',             // maže jen vlastní klíč (ownership)
        'MfaController@status',                   // vlastní MFA
        'MfaController@setup',
        'MfaController@confirm',
        'MfaController@regenerateRecovery',
        'MfaController@disable',
        'UserController@toggleDarkMode',          // vlastní UI preference
        'SavedFilterController@index',            // vlastní uložené filtry (ownership)
        'SavedFilterController@store',
        'SavedFilterController@destroy',
        'FeeController@show',                     // metoda neexistuje (mrtvá resource routa)
        'SpeedClassController@show',              // jen redirect na chráněný index
        'closure:/',                              // dashboard (jen auth)
        'closure:/ares/lookup/{ico}',             // veřejný rejstřík ARES (necitlivé)
        'closure:/streets/by-town/{townId}',      // číselník ulic pro dropdown
    ];

    public function test_zadna_neocekavana_neochranovana_routa(): void
    {
        $gaps = (new AclRouteCoverage())->scan()['gaps'];

        $unexpected = [];
        foreach ($gaps as $g) {
            if (!in_array($g['action'], self::ALLOWED, true)) {
                $unexpected[] = $g['methods'] . ' ' . $g['uri'] . ' → ' . $g['action'];
            }
        }

        $this->assertSame(
            [],
            $unexpected,
            "Nová přihlášená routa bez autorizace. Přidej aclCheck/abort v controlleru "
            . "nebo acl: middleware; pokud je to záměr (self-service/ownership/ref. data), "
            . "přidej ji do ALLOWED v tomto testu s odůvodněním."
        );
    }
}
