<?php

namespace App\Domains\Meetings\Actions;

use App\Domains\Meetings\Exceptions\AttendanceRejected;
use App\Domains\Meetings\Repositories\MeetingRepository;
use App\Models\Meeting;

/**
 * Plná moc: nepřítomného seniora zastupuje fyzicky přítomný (ne on sám);
 * null zmocněnec = zrušit. Zastoupený tím není přítomen osobně.
 */
class SetProxyAction
{
    public function __construct(private MeetingRepository $meetings)
    {
    }

    /** @throws AttendanceRejected */
    public function handle(Meeting $meeting, int $memberId, ?int $holderId): void
    {
        if ($meeting->status === Meeting::STATUS_CLOSED) {
            throw new AttendanceRejected('meeting', 'Schůze je uzavřená.');
        }
        abort_unless($this->meetings->isSenior($memberId), 422, 'Člen nemá hlasovací právo.');

        if ($holderId !== null) {
            if ($holderId === $memberId) {
                throw new AttendanceRejected('proxy', 'Člen nemůže zastupovat sám sebe.');
            }
            if (!$this->meetings->isPresent($meeting->id, $holderId)) {
                throw new AttendanceRejected('proxy', 'Zmocněnec musí být fyzicky přítomen.');
            }
        }

        $att = $this->meetings->attendance($meeting->id, $memberId);
        $att->proxy_holder_id = $holderId;
        if ($holderId !== null) {
            $att->checked_in_at = null;
        }
        $att->save();
    }
}
