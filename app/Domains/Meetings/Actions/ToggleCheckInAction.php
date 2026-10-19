<?php

namespace App\Domains\Meetings\Actions;

use App\Domains\Meetings\Exceptions\AttendanceRejected;
use App\Domains\Meetings\Repositories\MeetingRepository;
use App\Models\Meeting;

/** Přepne osobní přítomnost seniora; příchod ruší plnou moc za něj (nesené moci drží dál). */
class ToggleCheckInAction
{
    public function __construct(private MeetingRepository $meetings)
    {
    }

    /** @throws AttendanceRejected */
    public function handle(Meeting $meeting, int $memberId): void
    {
        if ($meeting->status === Meeting::STATUS_CLOSED) {
            throw new AttendanceRejected('meeting', 'Schůze je uzavřená.');
        }
        abort_unless($this->meetings->isSenior($memberId), 422, 'Člen nemá hlasovací právo.');

        $att = $this->meetings->attendance($meeting->id, $memberId);
        if ($att->checked_in_at !== null) {
            $att->checked_in_at = null;
        } else {
            $att->checked_in_at   = now();
            $att->proxy_holder_id = null;
        }
        $att->save();
    }
}
