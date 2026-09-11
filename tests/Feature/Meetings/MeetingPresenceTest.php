<?php

namespace Tests\Feature\Meetings;

use App\Http\Controllers\MeetingController;
use App\Models\Meeting;
use App\Models\MeetingAttendance;
use Closure;
use Tests\DatabaseTestCase;

/**
 * Pravidlo degradace seniora „3× po sobě nepřítomen" (consecutiveAbsences) +
 * účast plnou mocí se počítá jako přítomnost. Bez potřeby reálného člena —
 * logika čte jen meetings + meeting_attendances podle member_id.
 */
class MeetingPresenceTest extends DatabaseTestCase
{
    private Closure $absences;
    private int $mid = 990001; // fiktivní member_id mimo reálná data

    protected function setUp(): void
    {
        parent::setUp();
        $ctrl = (new \ReflectionClass(MeetingController::class))->newInstanceWithoutConstructor();
        $this->absences = Closure::bind(
            fn (int $memberId, ?int $exclude = null) => $this->consecutiveAbsences($memberId, $exclude),
            $ctrl,
            MeetingController::class
        );
    }

    private function closedMeeting(string $date): Meeting
    {
        return Meeting::create([
            'title' => 'test ' . $date, 'held_on' => $date,
            'type' => 'clenska_schuze', 'status' => Meeting::STATUS_CLOSED, 'quorum_percent' => 50,
        ]);
    }

    public function test_three_absences_in_a_row(): void
    {
        foreach (['2099-01-01', '2099-02-01', '2099-03-01'] as $d) {
            $m = $this->closedMeeting($d);
            MeetingAttendance::create(['meeting_id' => $m->id, 'member_id' => $this->mid]); // absent
        }
        $this->assertSame(3, ($this->absences)($this->mid));
    }

    public function test_attendance_resets_streak(): void
    {
        $m1 = $this->closedMeeting('2099-01-01');
        MeetingAttendance::create(['meeting_id' => $m1->id, 'member_id' => $this->mid]); // absent (nejstarší)
        $m2 = $this->closedMeeting('2099-02-01');
        MeetingAttendance::create(['meeting_id' => $m2->id, 'member_id' => $this->mid, 'checked_in_at' => now()]); // přítomen
        $m3 = $this->closedMeeting('2099-03-01');
        MeetingAttendance::create(['meeting_id' => $m3->id, 'member_id' => $this->mid]); // absent (nejnovější)
        // od nejnovější: absent(m3)=1, pak m2 přítomen → série končí
        $this->assertSame(1, ($this->absences)($this->mid));
    }

    public function test_proxy_counts_as_attendance(): void
    {
        $m = $this->closedMeeting('2099-03-01');
        MeetingAttendance::create(['meeting_id' => $m->id, 'member_id' => $this->mid, 'proxy_holder_id' => 123]); // zastoupen
        $this->assertSame(0, ($this->absences)($this->mid)); // plná moc = účast
    }

    public function test_missing_row_stops_streak(): void
    {
        // nejnovější = absent, starší = žádný řádek (nebyl senior) → série = 1
        $mOld = $this->closedMeeting('2099-01-01'); // žádný řádek pro $this->mid
        $mNew = $this->closedMeeting('2099-03-01');
        MeetingAttendance::create(['meeting_id' => $mNew->id, 'member_id' => $this->mid]); // absent
        $this->assertSame(1, ($this->absences)($this->mid));
    }

    public function test_exclude_current_meeting(): void
    {
        $m1 = $this->closedMeeting('2099-01-01');
        MeetingAttendance::create(['meeting_id' => $m1->id, 'member_id' => $this->mid]);
        $m2 = $this->closedMeeting('2099-02-01');
        MeetingAttendance::create(['meeting_id' => $m2->id, 'member_id' => $this->mid]);
        // vyloučíme m2 → zůstane jen m1 absence = 1
        $this->assertSame(1, ($this->absences)($this->mid, $m2->id));
    }
}
