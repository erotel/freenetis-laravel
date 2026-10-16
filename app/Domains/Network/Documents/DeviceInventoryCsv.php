<?php

namespace App\Domains\Network\Documents;

use App\Models\Device;
use App\Models\Iface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV inventář VŠECH zařízení — vše jako na detailu zařízení (info, technici,
 * rozhraní s MAC/IPv4/IPv6), BEZ PPPoE. Přihlašovací údaje (login / heslo +
 * WPA2) jen s příslušným právem. Streamované po 200 kvůli tisícům zařízení;
 * oddělovač ';' + UTF-8 BOM (Excel/CZ).
 */
class DeviceInventoryCsv
{
    public function download(bool $canLogin, bool $canPass): StreamedResponse
    {
        $typeLabels = Iface::typeLabels();

        $columns = [
            'ID', 'Název', 'Typ', 'Obchodní název', 'Uživatel', 'Člen', 'Adresa umístění',
            'Operační systém', 'Login', 'Heslo', 'WPA2 klíč', 'Poslední přístup',
            'Cena', 'Měsíční splátka', 'Datum koupě', 'Komentář', 'Technici zařízení',
            'Rozhraní (detail)', 'MAC adresy', 'IPv4 adresy', 'IPv6 adresy',
        ];
        $filename = 'zarizeni-inventar-' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($columns, $canLogin, $canPass, $typeLabels) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM
            fputcsv($out, $columns, ';');

            Device::with([
                'enumType', 'user.member',
                'addressPoint.street', 'addressPoint.town',
                'deviceEngineers.user',
                'ifaces.ipAddresses', 'ifaces.ip6Addresses',
            ])->orderBy('id')->chunk(200, function ($devices) use ($out, $canLogin, $canPass, $typeLabels) {
                foreach ($devices as $d) {
                    $ap = $d->addressPoint;
                    $addr = $ap
                        ? trim(trim(($ap->street->street ?? '') . ' ' . ($ap->street_number ?? ''))
                            . ', ' . trim(($ap->town->town ?? '') . ' ' . ($ap->town->zip_code ?? '')), ' ,')
                        : '';

                    $techs = $d->deviceEngineers->map(function ($e) {
                        $u = $e->user;
                        if (!$u) return '#' . $e->user_id;
                        $jm = trim(($u->name ?? '') . ' ' . ($u->surname ?? ''));
                        return $u->login . ($jm !== '' ? " ({$jm})" : '');
                    })->implode('; ');

                    $ifaceParts = []; $macs = []; $v4 = []; $v6 = [];
                    foreach ($d->ifaces as $if) {
                        $ips = $if->ipAddresses->pluck('ip_address')->all();
                        $p6  = $if->ip6Addresses->pluck('ip_address')->all();
                        if ($if->mac) $macs[] = $if->mac;
                        $v4 = array_merge($v4, $ips);
                        $v6 = array_merge($v6, $p6);
                        $ifaceParts[] = ($if->name ?? '?')
                            . '(' . ($typeLabels[$if->type] ?? $if->type) . ')'
                            . ' MAC=' . ($if->mac ?: '-')
                            . ' IPv4=[' . implode(',', $ips) . ']'
                            . ' IPv6=[' . implode(',', $p6) . ']';
                    }

                    // Credentialy jsou šifrované at-rest — dešifrování obalíme, ať
                    // jeden vadný řádek neshodí celý export.
                    $login = $canLogin ? ($d->login ?? '') : '';
                    $pass = ''; $wpa = '';
                    if ($canPass) {
                        try { $pass = (string) ($d->password ?? ''); } catch (\Throwable $e) { $pass = '(nešlo dešifrovat)'; }
                        try { $wpa  = (string) ($d->wpa_key ?? ''); } catch (\Throwable $e) { $wpa = '(nešlo dešifrovat)'; }
                    }

                    fputcsv($out, [
                        $d->id,
                        $d->name,
                        $d->enumType->value ?? $d->type,
                        $d->trade_name ?? '',
                        $d->user?->full_name ?? '',
                        $d->user?->member?->name ?? '',
                        $addr,
                        $d->operating_system ?? '',
                        $login,
                        $pass,
                        $wpa,
                        $d->access_time ?? '',
                        $d->price ?? '',
                        $d->payment_rate ?? '',
                        ($d->buy_date && $d->buy_date !== '0000-00-00') ? $d->buy_date : '',
                        $d->comment ?? '',
                        $techs,
                        implode(' || ', $ifaceParts),
                        implode('; ', $macs),
                        implode('; ', $v4),
                        implode('; ', $v6),
                    ], ';');
                }
            });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
