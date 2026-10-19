<?php

namespace App\Domains\Notifications\Queries;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Ruční správa přesměrování: seznam aktivních přesměrování (filtr IP, jméno
 * člena — přímý vlastník IP i přes zařízení, typ zprávy) a zprávy, které
 * jde aktivovat ručně (bez systémových typů a prázdného textu).
 */
class ManualRedirectsQuery
{
    // Kohana Message_Model::can_be_activate_directly() — vyloučené typy
    public const EXCLUDED_TYPES = [1, 2, 3, 4, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 21, 22, 23, 24, 27, 28];

    public function list(?string $ip, ?string $member, ?string $type, int $perPage): LengthAwarePaginator
    {
        $query = DB::table('messages_ip_addresses as mia')
            ->join('ip_addresses as ip', 'ip.id', '=', 'mia.ip_address_id')
            ->join('messages as msg', 'msg.id', '=', 'mia.message_id')
            ->leftJoin('ifaces as i', 'i.id', '=', 'ip.iface_id')
            ->leftJoin('devices as d', 'd.id', '=', 'i.device_id')
            ->leftJoin('users as u', 'u.id', '=', 'd.user_id')
            ->leftJoin('members as m_user', 'm_user.id', '=', 'u.member_id')
            ->leftJoin('members as m_direct', 'm_direct.id', '=', 'ip.member_id')
            ->selectRaw('
                mia.message_id, mia.ip_address_id, mia.comment, mia.datetime,
                ip.ip_address,
                msg.name as msg_name, msg.type as msg_type,
                COALESCE(m_direct.id, m_user.id) as member_id,
                COALESCE(m_direct.name, m_user.name) as member_name
            ');

        if ($ip) {
            $query->where('ip.ip_address', 'like', "%{$ip}%");
        }
        if ($member) {
            $query->where(function ($q) use ($member) {
                $q->where('m_direct.name', 'like', "%{$member}%")->orWhere('m_user.name', 'like', "%{$member}%");
            });
        }
        if ($type) {
            $query->where('msg.type', (int) $type);
        }

        return $query->orderByDesc('mia.datetime')->paginate($perPage)->withQueryString();
    }

    /** @return array<int, string> id => název, podle typu a názvu */
    public function activatableMessages(): array
    {
        return DB::table('messages')
            ->whereNotIn('type', self::EXCLUDED_TYPES)
            ->whereRaw("TRIM(COALESCE(text,'')) != ''")
            ->orderBy('type')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn ($m) => [$m->id => $m->name])
            ->all();
    }
}
