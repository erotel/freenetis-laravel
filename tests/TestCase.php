<?php

namespace Tests;

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
        $db = (string) config('database.connections.mysql.database');
        if (!str_ends_with($db, '_test')) {
            throw new RuntimeException("Testy běží jen proti DB s příponou _test, ne „{$db}“ (viz phpunit.xml).");
        }
    }
}
