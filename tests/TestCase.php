<?php

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Pojistka: testy (DatabaseTransactions, DDL autocommit, Setting::set…)
        // smí běžet jen proti testovací DB — nikdy proti živé freenetis (.59
        // z ní čte RADIUS, .54 je produkce). Připraví ji `testing:refresh-db`.
        foreach (['mysql', 'contracts'] as $conn) {
            $db = (string) config("database.connections.{$conn}.database");
            if (!str_ends_with($db, '_test')) {
                throw new RuntimeException("Testy běží jen proti DB s příponou _test, ne „{$db}“ ({$conn}, viz phpunit.xml).");
            }
        }
    }

    /**
     * Session v testu přežívá mezi requesty (i hash hesla, který si ukládá
     * AuthenticateSession). Přepnutí actingAs na jiného uživatele by pak
     * vypadalo jako změna hesla → odhlášení. V reálu má každý uživatel
     * svou session, proto hash při přepnutí zapomeneme.
     */
    public function be(Authenticatable $user, $guard = null)
    {
        if ($this->app->bound('session.store')) {
            $this->app['session.store']->forget('password_hash_' . $this->app['auth']->getDefaultDriver());
        }
        return parent::be($user, $guard);
    }
}
