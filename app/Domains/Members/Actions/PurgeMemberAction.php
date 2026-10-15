<?php

namespace App\Domains\Members\Actions;

use App\Domains\Members\Events\MemberPurged;
use App\Domains\Members\Exceptions\MemberNotPurgeable;
use App\Domains\Members\Repositories\MemberDeviceRepository;
use App\Domains\Members\Repositories\MemberRepository;
use App\Domains\Members\Support\FormerType;
use App\Helpers\MemberType;
use Illuminate\Support\Facades\DB;

/**
 * Trvalé smazání člena se všemi daty — jen čekatel (17/18) nebo bývalý
 * (15/16); aktivního je nejdřív třeba označit jako bývalého
 * ({@see MarkMemberFormerAction}). Smlouvy a audit řeší listenery {@see MemberPurged}.
 * Oprávnění kontroluje volající.
 */
class PurgeMemberAction
{
    public const PENDING_TYPES = [MemberType::PENDING_MEMBER, MemberType::PENDING_CUSTOMER];

    public function __construct(
        private MemberRepository $members,
        private MemberDeviceRepository $devices,
    ) {
    }

    public static function isPurgeable(int $type): bool
    {
        return in_array($type, self::PENDING_TYPES, true) || FormerType::isFormer($type);
    }

    /** @throws MemberNotPurgeable */
    public function handle(object $member): void
    {
        $id = (int) $member->id;
        if (!self::isPurgeable((int) $member->type)) {
            throw new MemberNotPurgeable('Aktivního člena nelze trvale smazat — nejdřív ho označte jako bývalého.');
        }

        DB::transaction(function () use ($id) {
            $this->devices->deleteDevices($id);
            $this->members->purge($id);
        });

        MemberPurged::dispatch($id, (array) $member);
    }
}
