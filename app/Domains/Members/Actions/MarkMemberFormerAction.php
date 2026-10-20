<?php

namespace App\Domains\Members\Actions;

use App\Domains\Audit\AuditLogger;
use App\Domains\Billing\Fees\MemberFeesTermination;
use App\Domains\Members\Events\MembershipEnded;
use App\Domains\Members\Repositories\MemberRepository;
use App\Domains\Members\Support\EndMode;
use App\Domains\Members\Support\FormerType;
use Illuminate\Support\Facades\DB;

/**
 * Trvale smazat, krok 1: aktivního člena označit jako bývalého k dnešku
 * (90→15, jinak 16), zamknout, rychlost „žádná", ukončit individuální
 * poplatky. Smlouvy řeší listenery {@see MembershipEnded} (mód bez e-mailu).
 *
 * Na rozdíl od Ukončit nemění prepaid flagy, VS ani zařízení (zachované
 * legacy chování). Oprávnění kontroluje volající.
 */
class MarkMemberFormerAction
{
    public function __construct(private MemberRepository $members)
    {
    }

    public function handle(object $member, ?string $byLogin): MembershipEnded
    {
        $id         = (int) $member->id;
        $type       = (int) $member->type;
        $formerType = FormerType::for($type);
        $leaving    = now()->format('Y-m-d');

        DB::transaction(function () use ($id, $member, $formerType, $leaving) {
            $this->members->markFormer($id, $formerType, $leaving);
            AuditLogger::log('updated', 'members', $id, [
                'type'           => $member->type,
                'locked'         => $member->locked,
                'leaving_date'   => $member->leaving_date,
                'speed_class_id' => $member->speed_class_id,
            ], [
                'type'           => $formerType,
                'locked'         => 1,
                'leaving_date'   => $leaving,
                'speed_class_id' => null,
            ]);
            MemberFeesTermination::deactivate($id, $leaving);
        });

        $event = new MembershipEnded($id, $type, $formerType, $leaving, EndMode::PLAIN, false, null, $byLogin);
        event($event);

        return $event;
    }
}
