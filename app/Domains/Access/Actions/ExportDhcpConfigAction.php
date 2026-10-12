<?php

namespace App\Domains\Access\Actions;

use App\Domains\Access\Dhcp\DhcpDeviceSettings;
use App\Domains\Access\Dhcp\DhcpServerBuilder;
use App\Domains\Access\Dhcp\MikrotikDhcpRenderer;
use App\Domains\Access\Repositories\DhcpExportRepository;
use App\Models\Device;
use App\Services\LanIsolationService;
use Carbon\Carbon;

/**
 * DHCP export pro MikroTik: detekce změn → sestavení serverů → RouterOS skript
 * (+ izolace LAN) → označení konzumenta jako aktuálního.
 *
 * Autorizaci řeší controller. $fromDhcpServer = volá DHCP server s platným
 * tokenem: jen tehdy se kontroluje „nic se nezměnilo" (→ null = 204) a
 * zapisuje stav synchronizace; přihlášený admin dostane export vždy a nic
 * neoznačí jako stažené.
 */
class ExportDhcpConfigAction
{
    public const FORMAT_FULL  = 'mikrotik-ip-dhcp-server';
    public const FORMAT_LEASE = 'mikrotik-ip-dhcp-server-lease';
    public const FORMATS      = [self::FORMAT_FULL, self::FORMAT_LEASE];

    public function __construct(
        private DhcpExportRepository $repo,
        private DhcpServerBuilder $builder,
        private MikrotikDhcpRenderer $renderer,
        private DhcpDeviceSettings $settings,
        private LanIsolationService $lanIsolation,
    ) {
    }

    /**
     * @param Carbon  $startedAt  okamžik PŘED načtením stavu subnetů — uloží se
     *                            jako high-water-mark klienta; změna přišlá během
     *                            exportu má dhcp_changed_at > startedAt a příští
     *                            fetch ji spolehlivě chytí (žádná ztracená změna).
     * @param ?string $client     per-konzument detekce (víc DHCP serverů čte stejné
     *                            subnety, každý vidí každou změnu právě jednou);
     *                            null = legacy sdílený boolean dhcp_expired.
     * @param string  $role       'static' = druhý (static-only) server v relay páru.
     * @return ?string            null = beze změny (HTTP 204)
     */
    public function handle(
        Device $device,
        string $format,
        bool $fromDhcpServer,
        bool $forced,
        ?string $client,
        string $role,
        Carbon $startedAt,
    ): ?string {
        if ($fromDhcpServer) {
            $this->repo->touchAccessTime($device);
        }

        if (!$forced && $fromDhcpServer && !$this->changedSinceLastExport($device, $client)) {
            return null;
        }

        $servers        = $this->builder->build($device);
        $relayInterface = $this->settings->relayInterface($device->id);

        $text = $format === self::FORMAT_FULL
            ? $this->renderer->renderFull(
                $servers,
                $relayInterface,
                $role === 'static' ? 'static' : 'primary',
                $this->settings->forceRadius($device->id),
                $this->settings->forceTr101($device->id),
            )
            : $this->renderer->renderLeaseOnly($servers);

        // Izolace klientů od vnitřní sítě — až na konec: RouterOS import se při
        // chybě na řádku zastaví, DHCP konfigurace tak projde vždy.
        if ($format === self::FORMAT_FULL) {
            $text .= $this->lanIsolation->renderMikrotik($device->id, $servers, $relayInterface);
        }

        // Zapiš, že tento konzument je aktuální. Per-client posune jen jeho
        // high-water-mark (ostatní DHCP servery změnu pořád uvidí); legacy shodí
        // sdílený flag jako dosud.
        if ($fromDhcpServer) {
            if ($client !== null) {
                $this->repo->markClientExported($client, $startedAt);
            } else {
                $this->repo->markSubnetsSynced($device);
            }
        }

        return $text;
    }

    private function changedSinceLastExport(Device $device, ?string $client): bool
    {
        if ($client === null) {
            return $this->repo->hasExpiredSubnet($device);
        }

        $seen = $this->repo->clientExportedAt($client);
        if ($seen === null) {
            return true;
        }
        // Striktní `<` (ne `<=`): při shodě raději re-export — import je
        // idempotentní, kdežto vynechání by znamenalo ztracenou změnu.
        $latestChange = $this->repo->latestSubnetChange($device);
        return !($latestChange === null || $latestChange->lt($seen));
    }
}
