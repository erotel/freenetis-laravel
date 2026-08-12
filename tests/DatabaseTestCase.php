<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;

/**
 * Základ pro testy, které sahají do databáze.
 *
 * Testy běží proti reálné MySQL (viz phpunit.xml) — legacy schéma není v
 * migracích, takže SQLite/RefreshDatabase nejde použít. Místo toho každý test
 * obalíme DB transakcí a na konci rollbackujeme, takže žádná data nezůstanou.
 *
 * $connectionsToTransact zajišťuje rollback na OBOU connectionech, které projekt
 * používá: 'mysql' (freenetis) i 'contracts' (contractsdb).
 */
abstract class DatabaseTestCase extends TestCase
{
    use DatabaseTransactions;

    /** @var string[] Connections, které se mají obalit transakcí a rollbackovat. */
    protected $connectionsToTransact = ['mysql', 'contracts'];
}
