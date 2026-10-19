<?php

namespace App\Domains\Meetings\Actions;

use App\Domains\Meetings\Repositories\MeetingRepository;
use App\Models\Meeting;

/** Uzavře schůzi se snapshotem: každý současný senior bez řádku dostane absenci. */
class CloseMeetingAction
{
    public function __construct(private MeetingRepository $meetings)
    {
    }

    public function handle(Meeting $meeting): void
    {
        $existing = $this->meetings->attendeeIds($meeting->id)->flip();
        foreach ($this->meetings->seniorIds() as $memberId) {
            if (!isset($existing[$memberId])) {
                $this->meetings->addAbsence($meeting->id, (int) $memberId);
            }
        }
        $this->meetings->setStatus($meeting, Meeting::STATUS_CLOSED);
    }
}
