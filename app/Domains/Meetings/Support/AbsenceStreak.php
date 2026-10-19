<?php

namespace App\Domains\Meetings\Support;

use App\Domains\Meetings\Repositories\MeetingRepository;

/**
 * Pravidlo degradace seniora: po sobě jdoucí absence v UZAVŘENÝCH členských
 * schůzích od nejnovější. Účast (osobně NEBO plnou mocí) sérii ukončí;
 * chybějící řádek = tehdy nebyl senior → také konec (nepočítá se).
 */
class AbsenceStreak
{
    /** Kolik absencí v řadě = degradace na řadového člena. */
    public const DEGRADE_AFTER = 3;

    public function __construct(private MeetingRepository $meetings)
    {
    }

    /** @param ?int $excludeMeetingId vynechat schůzi (indikátor „před touto") */
    public function count(int $memberId, ?int $excludeMeetingId = null): int
    {
        $streak = 0;
        foreach ($this->meetings->closedMemberMeetingIds($excludeMeetingId) as $meetingId) {
            $att = $this->meetings->attendanceRow((int) $meetingId, $memberId);
            if (!$att || $att->counts()) {
                break;
            }
            $streak++;
        }
        return $streak;
    }
}
