<?php

namespace App\Domains\Members\Repositories;

use App\Models\MemberWhitelist;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/** Bílé listiny členů (members_whitelists) — výjimka z dlužnických přesměrování / upozornění. */
class WhitelistRepository
{
    /** Nejnovější první, se jménem člena; volitelně jen jednoho člena. */
    public function paginate(?int $memberId, int $perPage): LengthAwarePaginator
    {
        return DB::table('members_whitelists as mw')
            ->join('members as m', 'm.id', '=', 'mw.member_id')
            ->select('mw.*', 'm.name as member_name')
            ->when($memberId, fn ($q) => $q->where('mw.member_id', $memberId))
            ->orderByDesc('mw.id')
            ->paginate($perPage)->withQueryString();
    }

    public function findOrFail(int $id): MemberWhitelist
    {
        return MemberWhitelist::findOrFail($id);
    }

    public function findWithMemberOrFail(int $id): MemberWhitelist
    {
        return MemberWhitelist::with('member')->findOrFail($id);
    }

    public function create(int $memberId, array $data): MemberWhitelist
    {
        return MemberWhitelist::create(['member_id' => $memberId] + self::attributes($data));
    }

    public function update(MemberWhitelist $whitelist, array $data): void
    {
        $whitelist->update(self::attributes($data));
    }

    public function delete(MemberWhitelist $whitelist): void
    {
        $whitelist->delete();
    }

    /** Trvalá = do 9999-12-31. */
    private static function attributes(array $data): array
    {
        $permanent = (bool) ($data['permanent'] ?? false);

        return [
            'permanent' => $permanent,
            'since'     => $data['since'],
            'until'     => $permanent ? '9999-12-31' : $data['until'],
            'comment'   => $data['comment'] ?? null,
        ];
    }
}
