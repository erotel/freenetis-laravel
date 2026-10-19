<?php

namespace App\Domains\Network\Repositories;

use App\Models\MemberLanAccess;

/** Výjimky z izolace klientů (member_lan_access) — kam do vnitřní sítě smí člen. */
class LanAccessRepository
{
    /** @return bool true = nově přidáno, false = člen cíl už měl */
    public function add(int $memberId, string $destination, ?string $comment, ?int $userId): bool
    {
        return MemberLanAccess::firstOrCreate(
            ['member_id' => $memberId, 'destination' => $destination],
            ['comment' => $comment, 'created_by' => $userId]
        )->wasRecentlyCreated;
    }

    public function findOrFail(int $id): MemberLanAccess
    {
        return MemberLanAccess::findOrFail($id);
    }

    public function delete(MemberLanAccess $access): void
    {
        $access->delete();
    }
}
