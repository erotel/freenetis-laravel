<?php

namespace App\Domains\Notifications\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Přesměrování IP na zprávu (messages_ip_addresses) a IP členů. */
class RedirectionRepository
{
    /** id IPv4 adres na zařízeních uživatelů člena. */
    public function memberIpIds(int $memberId): Collection
    {
        return DB::table('ip_addresses as ia')
            ->join('ifaces as i', 'i.id', '=', 'ia.iface_id')
            ->join('devices as d', 'd.id', '=', 'i.device_id')
            ->join('users as u', 'u.id', '=', 'd.user_id')
            ->where('u.member_id', $memberId)
            ->pluck('ia.id');
    }

    /** Aktivace po jedné IP s upsertem (existující přepíše autora, komentář, čas). @return int počet IP */
    public function upsert(int $messageId, iterable $ipIds, ?int $userId, ?string $comment, string $now): int
    {
        $n = 0;
        foreach ($ipIds as $ipId) {
            DB::statement('
                INSERT INTO messages_ip_addresses (message_id, ip_address_id, user_id, comment, datetime)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), comment = VALUES(comment), datetime = VALUES(datetime)
            ', [$messageId, $ipId, $userId, $comment, $now]);
            $n++;
        }
        return $n;
    }

    /** Aktivace nahrazením (smazat + vložit dávkou). @return int počet IP */
    public function replace(int $messageId, Collection $ipIds, ?int $userId, ?string $comment, string $now): int
    {
        DB::table('messages_ip_addresses')->where('message_id', $messageId)->whereIn('ip_address_id', $ipIds)->delete();
        $rows = $ipIds->map(fn ($ipId) => [
            'message_id' => $messageId, 'ip_address_id' => $ipId, 'user_id' => $userId, 'comment' => $comment, 'datetime' => $now,
        ])->all();
        DB::table('messages_ip_addresses')->insertOrIgnore($rows);
        return count($rows);
    }

    /** Zruší všechna přesměrování na zprávy (přestavba automatických). */
    public function clear(array $messageIds): void
    {
        DB::table('messages_ip_addresses')->whereIn('message_id', $messageIds)->delete();
    }

    /** Automatické přesměrování IP na zprávu (existující ponechá). */
    public function addAuto(int $messageId, iterable $ipIds, int $userId, string $comment): void
    {
        $now  = now();
        $rows = collect($ipIds)->map(fn ($ipId) => [
            'message_id' => $messageId, 'ip_address_id' => $ipId, 'user_id' => $userId, 'comment' => $comment, 'datetime' => $now,
        ])->all();
        if ($rows) {
            DB::table('messages_ip_addresses')->insertOrIgnore($rows);
        }
    }

    /** @return int počet zrušených přesměrování */
    public function remove(int $messageId, iterable $ipIds): int
    {
        return $this->removeFrom([$messageId], $ipIds);
    }

    /** Zruší přesměrování IP na kteroukoli z daných zpráv. @return int počet */
    public function removeFrom(array $messageIds, iterable $ipIds): int
    {
        return DB::table('messages_ip_addresses')->whereIn('message_id', $messageIds)
            ->whereIn('ip_address_id', collect($ipIds)->all())->delete();
    }

    /** Členové s platným whitelistem k dnešku (klíče pole = member_id). */
    public function whitelisted(array $memberIds): array
    {
        return DB::table('members_whitelists')
            ->whereIn('member_id', $memberIds)
            ->where('since', '<=', now()->toDateString())
            ->where('until', '>=', now()->toDateString())
            ->pluck('member_id')->flip()->toArray();
    }

    /** Kontakty daného typu (20 e-mail, 21 telefon) všech uživatelů členů. */
    public function contacts(array $memberIds, int $type): Collection
    {
        return DB::table('contacts as c')
            ->join('users_contacts as uc', 'uc.contact_id', '=', 'c.id')
            ->join('users as u', 'u.id', '=', 'uc.user_id')
            ->whereIn('u.member_id', $memberIds)
            ->where('c.type', $type)
            ->pluck('c.value');
    }

    /** Řádek IP adresy (cíl ručního přesměrování), nebo null. */
    public function ip(int $ipId): ?object
    {
        return DB::table('ip_addresses')->where('id', $ipId)->first();
    }

    /** IP člena přímo přidělené (ip_addresses.member_id) i přes jeho zařízení. */
    public function memberAllIpIds(int $memberId): Collection
    {
        return DB::table('ip_addresses as ip')
            ->leftJoin('ifaces as i', 'i.id', '=', 'ip.iface_id')
            ->leftJoin('devices as d', 'd.id', '=', 'i.device_id')
            ->leftJoin('users as u', 'u.id', '=', 'd.user_id')
            ->where(function ($q) use ($memberId) {
                $q->where('ip.member_id', $memberId)->orWhere('u.member_id', $memberId);
            })
            ->pluck('ip.id')
            ->unique();
    }

    /** Přesměrování člena (přímé IP i přes zařízení) seskupená podle zprávy, nejnovější první. */
    public function memberRedirections(int $memberId): Collection
    {
        return DB::table('messages_ip_addresses as mia')
            ->join('ip_addresses as ip', 'ip.id', '=', 'mia.ip_address_id')
            ->join('messages as msg', 'msg.id', '=', 'mia.message_id')
            ->leftJoin('ifaces as i', 'i.id', '=', 'ip.iface_id')
            ->leftJoin('devices as d', 'd.id', '=', 'i.device_id')
            ->leftJoin('users as u', 'u.id', '=', 'd.user_id')
            ->where(function ($q) use ($memberId) {
                $q->where('ip.member_id', $memberId)->orWhere('u.member_id', $memberId);
            })
            ->select('mia.message_id', 'mia.ip_address_id', 'mia.comment', 'mia.datetime',
                     'ip.ip_address', 'msg.name as msg_name', 'msg.type as msg_type')
            ->orderByDesc('mia.datetime')
            ->get()
            ->groupBy('message_id');
    }
}
