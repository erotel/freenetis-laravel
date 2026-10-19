<?php

namespace App\Domains\Meetings\Actions;

use App\Domains\Meetings\Repositories\MeetingRepository;
use App\Models\Meeting;

/**
 * Uzavře schůzi se snapshotem: každý současný senior bez řádku dostane
 * absenci. Už uzavřenou nechá být — jinak by senior přidaný po schůzi
 * dostal zpětně absenci (a počítala by se mu do série pro degradaci).
 */
class CloseMeetingAction
{
    public function __construct(private MeetingRepository $meetings)
    {
    }

    public function handle(Meeting $meeting): void
    {
        if ($meeting->status === Meeting::STATUS_CLOSED) {
            return;
        }
        $existing = $this->meetings->attendeeIds($meeting->id)->flip();
        foreach ($this->meetings->seniorIds() as $memberId) {
            if (!isset($existing[$memberId])) {
                $this->meetings->addAbsence($meeting->id, (int) $memberId);
            }
        }
        $this->meetings->setStatus($meeting, Meeting::STATUS_CLOSED);
    }
}
