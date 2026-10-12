<?php

namespace App\Console\Commands;

use App\Domains\Access\Actions\EnsurePppoeCredentialAction;
use App\Domains\Access\Repositories\PppoeCredentialRepository;
use App\Models\Iface;
use Illuminate\Console\Command;

/**
 * Hromadné vygenerování PPPoE credentialů pro EXISTUJÍCÍ přípojky (přechod
 * MAC/IP → PPPoE). Pro každý přípojný iface (zákaznická statická IP, gateway=0,
 * patří členovi ≠ asociace) založí pppoe_secret (username=variabilní symbol / -N,
 * silné heslo). Idempotentní — přípojku, která už credential má, přeskočí.
 *
 * Neovlivní běžící MAC/IP provoz: credential jen „leží" v pppoe_secrets a RADIUS
 * ho začne servírovat teprve až zákazník přepne CPE na PPPoE (typicky při výjezdu
 * na WPA2). Distribuce hesel = karta „PPPoE přístup" na detailu zařízení.
 */
class PppoeGenerate extends Command
{
    protected $signature = 'pppoe:generate {--dry-run : jen spočítá, nic nezapíše}';
    protected $description = 'Vygeneruje PPPoE credentialy pro existující přípojky (příprava přechodu MAC/IP → PPPoE)';

    public function handle(PppoeCredentialRepository $credentials, EnsurePppoeCredentialAction $ensure): int
    {
        $dry = (bool) $this->option('dry-run');

        $ifaceIds = $credentials->customerConnectionIfaceIds();

        // Existující credentialy předhčteme jedním dotazem (ne find() per iface).
        $existing = $credentials->existingIfaceIds();

        $gen = 0; $skip = 0; $fail = 0;
        foreach ($ifaceIds as $id) {
            if (isset($existing[$id])) {
                $skip++;
                continue;
            }
            if ($dry) {
                $gen++;
                continue;
            }
            $iface = Iface::find($id);
            if ($iface && $ensure->handle($iface)) {
                $gen++;
            } else {
                $fail++;
            }
        }

        $this->info(($dry ? '[dry-run] ' : '')
            . 'Přípojek: ' . count($ifaceIds)
            . ", vygenerováno: {$gen}, přeskočeno (už mají): {$skip}, bez člena/selhalo: {$fail}");

        return self::SUCCESS;
    }
}
