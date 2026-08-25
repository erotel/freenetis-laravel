<?php

namespace App\Console\Commands;

use App\Models\Iface;
use App\Models\PppoeSecret;
use App\Services\PppoeSecretService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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

    public function handle(PppoeSecretService $svc): int
    {
        $dry = (bool) $this->option('dry-run');

        $ifaceIds = DB::table('ip_addresses as ip')
            ->join('ifaces as i', 'i.id', '=', 'ip.iface_id')
            ->join('devices as d', 'd.id', '=', 'i.device_id')
            ->join('users as u', 'u.id', '=', 'd.user_id')
            ->where('ip.gateway', 0)
            ->where('ip.service', 0)
            ->whereNotNull('u.member_id')
            ->where('u.member_id', '!=', 1) // asociace = infrastruktura, ne zákazník
            ->distinct()
            ->pluck('i.id');

        // Existující credentialy předhčteme jedním dotazem (ne find() per iface).
        $existing = PppoeSecret::pluck('iface_id')->flip();

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
            if ($iface && $svc->ensureForIface($iface)) {
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
