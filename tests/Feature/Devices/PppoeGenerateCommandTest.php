<?php

namespace Tests\Feature\Devices;

use App\Models\PppoeSecret;
use Tests\DatabaseTestCase;

/**
 * Hromadné vygenerování PPPoE credentialů pro existující přípojky (pppoe:generate).
 * Ověřujeme, že --dry-run nic nezapíše a reportuje počty.
 */
class PppoeGenerateCommandTest extends DatabaseTestCase
{
    public function test_dry_run_nezapise_zadny_credential(): void
    {
        $before = PppoeSecret::count();

        $this->artisan('pppoe:generate', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('[dry-run]');

        $this->assertSame($before, PppoeSecret::count(), 'dry-run nesmí zapsat');
    }
}
