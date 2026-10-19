<?php

namespace App\Http\Controllers;

use App\Domains\Access\Gateway\GatewayAuth;
use App\Domains\Access\Gateway\QosExport;
use App\Domains\Access\Repositories\GatewayExportRepository;
use App\Domains\Network\Support\Ip6FromIpv4;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Machine-to-machine HTTP API pro brány, routery a AP (Kohana
 * Web_interface_Controller). Bez uživatelského přihlášení — autentizace
 * tokenem / IP allowlistem (GatewayAuth), data v app/Domains/Access.
 */
class WebInterfaceController extends Controller
{
    public function __construct(private GatewayExportRepository $exports)
    {
    }

    private function guardTrusted(): void
    {
        if (!GatewayAuth::allows(request())) {
            abort(403);
        }
    }

    /** Exporty přesměrování: nejdřív zapnuté přesměrování, pak autentizace. */
    private function guardRedirection(): void
    {
        if (!GatewayAuth::redirectionEnabled()) {
            abort(403);
        }
        $this->guardTrusted();
    }

    /** Brána si podle redirection_state hlídá čerstvost seznamů. */
    private function touchRedirectionState(): void
    {
        Setting::set('redirection_state', now()->format('Y-m-d H:i:s'));
    }

    private function textResponse(array $lines): Response
    {
        return response(implode("\n", $lines) . "\n")
            ->header('Content-Type', 'text/plain; charset=utf-8');
    }

    // ── přesměrování ─────────────────────────────────────────────────────────

    public function redirectedRanges(): Response
    {
        $this->guardRedirection();
        $lines = $this->exports->redirectedRanges();
        $this->touchRedirectionState();
        return $this->textResponse($lines);
    }

    public function allowedIpAddresses(): Response
    {
        $this->guardRedirection();
        $lines = $this->exports->allowedIps();
        $this->touchRedirectionState();
        return $this->textResponse($lines);
    }

    public function unallowedIpAddresses(?int $messageType = null): Response
    {
        $this->guardRedirection();
        $lines = $this->exports->redirectedIps($messageType);
        $this->touchRedirectionState();
        return $this->textResponse($lines);
    }

    public function selfCancelableIpAddresses(): Response
    {
        $this->guardRedirection();
        $lines = $this->exports->selfCancelableIps();
        $this->touchRedirectionState();
        return $this->textResponse($lines);
    }

    /** Odvozené IPv6 /56 nepřesměrovaných 10.x adres (redirection_state se nemění). */
    public function allowedIp6Addresses(): Response
    {
        $this->guardRedirection();

        // prázdná hodnota v DB (legacy import) → default, ne ":Y:Z00::/"
        $prefix = Setting::get('ipv6_prefix') ?: '2a07:9c0';
        $mask   = Setting::get('ipv6_mask')   ?: '56';

        $items = [];
        foreach ($this->exports->allowedIps() as $ip) {
            if (($v6 = Ip6FromIpv4::deriveWith($ip, $prefix, $mask)) !== null) {
                $items[] = $v6;
            }
        }
        return $this->textResponse($items);
    }

    // ── RADIUS / QoS ─────────────────────────────────────────────────────────

    public function ipv6Radius(): Response
    {
        $this->guardTrusted();
        return $this->textResponse($this->exports->ipv6WithMac());
    }

    public function qosJson(QosExport $qos)
    {
        $this->guardTrusted();
        return response()->json(['generated_at' => gmdate('c')] + $qos->build());
    }

    // ── port forwardy / NAT 1:1 ──────────────────────────────────────────────

    public function publicPortForwardsJson()
    {
        $this->guardTrusted();
        return response()->json([
            'generated_at'         => gmdate('c'),
            'public_port_forwards' => $this->exports->portForwards(),
        ]);
    }

    public function publicIpNat1to1Json()
    {
        $this->guardTrusted();
        return response()->json([
            'generated_at'       => gmdate('c'),
            'public_ip_nat_1to1' => $this->exports->nat1to1(),
        ]);
    }

    /** „proto;public_ip;pub_ports;private_ip;lan_ports" (porty a:b / a-b). */
    public function publicPortForwardsTxt(): Response
    {
        $this->guardTrusted();

        $lines = [];
        foreach ($this->exports->symmetricPortForwards() as $r) {
            $pubFrom  = (int) $r->public_port_from;
            $pubTo    = (int) $r->public_port_to;
            $lanFrom  = (int) $r->private_port_from;
            $lanTo    = (int) $r->private_port_to;
            $pubPorts = $pubFrom === $pubTo ? (string) $pubFrom : "{$pubFrom}:{$pubTo}";
            $lanPorts = $lanFrom === $lanTo ? (string) $lanFrom : "{$lanFrom}-{$lanTo}";
            $lines[]  = strtolower($r->protocol) . ';' . $r->public_ip . ';' . $pubPorts . ';' . $r->private_ip . ';' . $lanPorts;
        }
        return $this->textResponse($lines);
    }

    /** „public_ip;private_ip", komentář jako „# …" řádek; volitelně ?scope=. */
    public function publicIpNat1to1Txt(Request $request): Response
    {
        $this->guardTrusted();

        $scope = trim((string) $request->query('scope', ''));
        if ($scope !== '' && !preg_match('~^[A-Za-z0-9_.:-]{1,64}$~', $scope)) {
            abort(400);
        }

        $lines = [];
        foreach ($this->exports->nat1to1Lines($scope !== '' ? $scope : null) as $r) {
            if (!empty($r->comment)) {
                $lines[] = '# ' . str_replace(["\r", "\n"], ' ', (string) $r->comment);
            }
            $lines[] = $r->public_ip . ';' . $r->private_ip;
        }
        return $this->textResponse($lines);
    }

    // ── SMTP výjimky ─────────────────────────────────────────────────────────

    public function smtpExceptionsJson()
    {
        $this->guardTrusted();
        return response()->json([
            'generated_at'    => gmdate('c'),
            'smtp_exceptions' => $this->exports->smtpExceptions(),
        ]);
    }

    public function smtpExceptionsTxt(): Response
    {
        $this->guardTrusted();
        return $this->textResponse(array_column($this->exports->smtpExceptions(), 'intip'));
    }
}
