<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Read-only modul pro zobrazení stavu IPTV předplatného (sledovanitv.cz) u členů.
 *
 * API: GET https://api.sledovanitv.cz/partner/api/get-users?partner=<x>&password=<y>
 * Vrací JSON {"status":1, "users": [{ id, partnerid, fullName, login, email,
 *   active, suspended, partnerActivation (YYYY-MM-DD), services: […] }]}.
 *
 * "TV aktivní" = partnerActivation >= dnes (testováno na partnerech 9776 a 1822).
 * `active` a `suspended` jsou zavádějící — zůstávají na 1/0 i pro vypršené účty.
 *
 * Mapping na FreenetIS: users[].partnerid (string) → members.id (int).
 * Záznamy bez partnerid jsou logovány a ignorovány.
 */
class SledovaniTvService
{
    private const API_BASE   = 'https://api.sledovanitv.cz/partner/api/get-users';
    private const API_MODIFY = 'https://api.sledovanitv.cz/partner/api/modify-user';

    public function isEnabled(): bool
    {
        return (bool) Setting::get('sledovanitv_enabled', 0);
    }

    /**
     * Stáhne JSON z API. Vrátí pole users[]. Vyhodí RuntimeException při chybě.
     */
    public function fetchUsers(): array
    {
        $partner  = (string) Setting::get('sledovanitv_partner', '');
        $password = (string) Setting::get('sledovanitv_password', '');

        if ($partner === '' || $password === '') {
            throw new \RuntimeException('SledovaniTV: chybí partner / password v Nastavení.');
        }

        $response = Http::timeout(60)->get(self::API_BASE, [
            'partner'  => $partner,
            'password' => $password,
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException("SledovaniTV API HTTP {$response->status()}: " . substr($response->body(), 0, 200));
        }

        $data = $response->json();
        if (!is_array($data) || ($data['status'] ?? 0) !== 1 || !isset($data['users']) || !is_array($data['users'])) {
            throw new \RuntimeException('SledovaniTV API: neočekávaný response (status != 1 nebo chybí users[]).');
        }

        return $data['users'];
    }

    /**
     * Změní partnerid u zadaného sledovanitv uživatele (selectovaného přes jejich userId).
     * Podle spec: pokud jsou zadány oba parametry, userId vybere uživatele a partnerid nastaví novou hodnotu.
     */
    public function modifyUserPartnerId(int $sledovanitvUserId, int $newPartnerId): void
    {
        $partner  = (string) Setting::get('sledovanitv_partner', '');
        $password = (string) Setting::get('sledovanitv_password', '');
        if ($partner === '' || $password === '') {
            throw new \RuntimeException('SledovaniTV: chybí partner / password v Nastavení.');
        }

        $response = Http::timeout(30)->get(self::API_MODIFY, [
            'partner'   => $partner,
            'password'  => $password,
            'userId'    => $sledovanitvUserId,
            'partnerid' => $newPartnerId,
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException("HTTP {$response->status()}: " . substr($response->body(), 0, 300));
        }

        $data = $response->json();
        if (!is_array($data) || ($data['status'] ?? 0) !== 1) {
            $errMsg = is_array($data)
                ? ($data['error'] ?? json_encode($data))
                : substr((string) $response->body(), 0, 300);
            throw new \RuntimeException('SledovaniTV modify-user neúspěch: ' . $errMsg);
        }
    }

    /**
     * Stáhne data, projde users[] a updatuje members.tv_*.
     * Vrátí stats: ['total', 'matched', 'active', 'unmatched', 'no_partnerid'].
     */
    public function syncToMembers(): array
    {
        $users = $this->fetchUsers();
        $today = date('Y-m-d');
        $now   = date('Y-m-d H:i:s');

        $stats = [
            'total'        => count($users),
            'matched'      => 0,
            'active'       => 0,
            'unmatched'    => 0, // partnerid set ale člen v FreenetIS neexistuje
            'no_partnerid' => 0, // partnerid null/prazdne
        ];

        // Resetneme stav všem před importem — kdo není v JSON aktivní, má 0
        // (běží denně, nepotřebujeme delta — celkem 571 záznamů).
        DB::table('members')->update([
            'tv_active'      => 0,
            'tv_valid_until' => null,
        ]);

        $existingIds = DB::table('members')->pluck('id')->flip()->toArray();

        foreach ($users as $u) {
            $partnerIdRaw = $u['partnerid'] ?? null;

            if ($partnerIdRaw === null || $partnerIdRaw === '') {
                $stats['no_partnerid']++;
                Log::warning('SledovaniTV sync: user bez partnerid', [
                    'sledovanitv_id' => $u['id'] ?? null,
                    'login'          => $u['login'] ?? null,
                    'email'          => $u['email'] ?? null,
                    'fullName'       => $u['fullName'] ?? null,
                ]);
                continue;
            }

            $memberId = (int) $partnerIdRaw;
            if (!isset($existingIds[$memberId])) {
                $stats['unmatched']++;
                Log::info('SledovaniTV sync: partnerid bez členství v FreenetIS', [
                    'partnerid' => $memberId,
                    'login'     => $u['login'] ?? null,
                ]);
                continue;
            }

            $partnerActivation = $u['partnerActivation'] ?? null;
            $isActive = $partnerActivation && $partnerActivation >= $today;

            DB::table('members')->where('id', $memberId)->update([
                'tv_active'      => $isActive ? 1 : 0,
                'tv_valid_until' => $partnerActivation ?: null,
                'tv_synced_at'   => $now,
            ]);

            $stats['matched']++;
            if ($isActive) $stats['active']++;
        }

        Setting::set('sledovanitv_last_sync', $now);
        Setting::set('sledovanitv_last_sync_status', sprintf(
            'OK: %d total, %d matched, %d active, %d unmatched, %d bez partnerid',
            $stats['total'], $stats['matched'], $stats['active'],
            $stats['unmatched'], $stats['no_partnerid']
        ));

        return $stats;
    }
}
