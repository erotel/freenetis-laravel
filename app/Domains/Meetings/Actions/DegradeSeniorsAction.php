<?php

namespace App\Domains\Meetings\Actions;

use App\Domains\Meetings\Repositories\MeetingRepository;
use App\Domains\Meetings\Support\AbsenceStreak;
use App\Models\Meeting;

/** Odebere hlasovací právo vybraným seniorům — jen těm, kdo fakt mají sérii absencí. */
class DegradeSeniorsAction
{
    public function __construct(private MeetingRepository $meetings, private AbsenceStreak $streak)
    {
    }

    /** @param int[] $memberIds @return int počet degradovaných */
    public function handle(Meeting $meeting, array $memberIds, ?int $userId): int
    {
        $reason = AbsenceStreak::DEGRADE_AFTER . '× po sobě nepřítomen na členské schůzi';
        $done = 0;
        foreach ($memberIds as $memberId) {
            if ($this->streak->count($memberId) < AbsenceStreak::DEGRADE_AFTER) {
                continue;
            }
            if ($this->meetings->revokeVote($memberId, $meeting->id, $reason, $userId)) {
                $done++;
            }
        }
        return $done;
    }
}
