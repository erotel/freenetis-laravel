<?php

namespace App\Domains\Notifications\Repositories;

use App\Domains\Network\Support\IpOwner;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Přesměrování IP na zprávu (messages_ip_addresses) a IP členů. */
class RedirectionRepository
{
    /** id IPv4 adres člena (přímo přiřazené i na jeho zařízeních — IpOwner). */
    public function memberIpIds(int $memberId): Collection
    {
        return IpOwner::ofMember($memberId)->pluck('ip.id');
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

    /** Zruší všechna přesměrování na zprávy (přestavba automatických). @return int počet zrušených */
    public function clear(array $messageIds): int
    {
        return DB::table('messages_ip_addresses')->whereIn('message_id', $messageIds)->delete();
    }

    /**
     * IP členů (vlastník podle IpOwner, kromě sdružení) v podsíti, která jim
     * není povolená (allowed_subnets.enabled) — jen u členů, kteří nějaké
     * přípojné místo mají.
     */
    public function ipsOutsideAllowedSubnets(): Collection
    {
        return IpOwner::query()
            ->join('members as m', 'm.id', '=', DB::raw(IpOwner::SQL))
            ->where('m.id', '!=', 1)
            ->whereNotNull('ip.subnet_id')
            ->whereNotExists(function ($q) {
                $q->from('allowed_subnets as als')
                  ->whereColumn('als.member_id', 'm.id')
                  ->whereColumn('als.subnet_id', 'ip.subnet_id')
                  ->where('als.enabled', 1);
            })
            ->whereExists(function ($q) {
                $q->from('allowed_subnets as als2')
                  ->whereColumn('als2.member_id', 'm.id');
            })
            ->pluck('ip.id')
            ->unique();
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
        return $this->memberIpIds($memberId);
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
            ->whereRaw(IpOwner::SQL . ' = ?', [$memberId])
            ->select('mia.message_id', 'mia.ip_address_id', 'mia.comment', 'mia.datetime',
                     'ip.ip_address', 'msg.name as msg_name', 'msg.type as msg_type')
            ->orderByDesc('mia.datetime')
            ->get()
            ->groupBy('message_id');
    }
}
