<?php

namespace App\Domains\Meetings\Repositories;

use App\Models\Meeting;
use App\Models\MeetingAttendance;
use App\Models\Member;
use App\Models\MemberVotingLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Členské schůze, docházka (meeting_attendances: checked_in_at = osobně,
 * proxy_holder_id = plná moc) a hlasovací právo seniorů (members.can_vote).
 */
class MeetingRepository
{
    public function paginate(int $perPage): LengthAwarePaginator
    {
        return Meeting::orderByDesc('held_on')->orderByDesc('id')->paginate($perPage);
    }

    public function find(int $id): ?Meeting
    {
        return Meeting::find($id);
    }

    public function findOrFail(int $id): Meeting
    {
        return Meeting::findOrFail($id);
    }

    public function create(array $data): Meeting
    {
        return Meeting::create($data + ['status' => Meeting::STATUS_PLANNED]);
    }

    public function setStatus(Meeting $meeting, string $status): void
    {
        $meeting->update(['status' => $status]);
    }

    public function seniorCount(): int
    {
        return Member::where('can_vote', 1)->count();
    }

    /** @return Collection<int> */
    public function seniorIds(): Collection
    {
        return Member::where('can_vote', 1)->pluck('id');
    }

    public function isSenior(int $memberId): bool
    {
        return Member::where('id', $memberId)->where('can_vote', 1)->exists();
    }

    /** Členové podle id (jen id + jméno), podle jména. */
    public function membersByName(Collection $ids): Collection
    {
        return Member::whereIn('id', $ids)->orderBy('name')->get(['id', 'name']);
    }

    /** Docházka schůze podle member_id. */
    public function attendances(int $meetingId): Collection
    {
        return MeetingAttendance::where('meeting_id', $meetingId)->get()->keyBy('member_id');
    }

    /** @return Collection<int> member_id s řádkem docházky */
    public function attendeeIds(int $meetingId): Collection
    {
        return MeetingAttendance::where('meeting_id', $meetingId)->pluck('member_id');
    }

    /** Kolik plných mocí nese který přítomný (proxy_holder_id => počet). */
    public function proxyLoad(int $meetingId): Collection
    {
        return MeetingAttendance::where('meeting_id', $meetingId)
            ->whereNotNull('proxy_holder_id')
            ->select('proxy_holder_id', DB::raw('COUNT(*) c'))
            ->groupBy('proxy_holder_id')->pluck('c', 'proxy_holder_id');
    }

    public function attendance(int $meetingId, int $memberId): MeetingAttendance
    {
        return MeetingAttendance::firstOrNew(['meeting_id' => $meetingId, 'member_id' => $memberId]);
    }

    public function isPresent(int $meetingId, int $memberId): bool
    {
        return MeetingAttendance::where('meeting_id', $meetingId)->where('member_id', $memberId)->whereNotNull('checked_in_at')->exists();
    }

    public function addAbsence(int $meetingId, int $memberId): void
    {
        MeetingAttendance::create(['meeting_id' => $meetingId, 'member_id' => $memberId, 'checked_in_at' => null, 'proxy_holder_id' => null]);
    }

    /** Uzavřené členské schůze od nejnovější (volitelně bez jedné). @return Collection<int> */
    public function closedMemberMeetingIds(?int $excludeMeetingId = null): Collection
    {
        return Meeting::where('status', Meeting::STATUS_CLOSED)
            ->where('type', 'clenska_schuze')
            ->when($excludeMeetingId, fn ($q) => $q->where('id', '<>', $excludeMeetingId))
            ->orderByDesc('held_on')->orderByDesc('id')->pluck('id');
    }

    public function attendanceRow(int $meetingId, int $memberId): ?MeetingAttendance
    {
        return MeetingAttendance::where('meeting_id', $meetingId)->where('member_id', $memberId)->first();
    }

    /** Odebere hlasovací právo + záznam do member_voting_logs. @return bool false = nebyl senior */
    public function revokeVote(int $memberId, int $meetingId, string $reason, ?int $userId): bool
    {
        $m = Member::find($memberId);
        if (!$m || !$m->can_vote) {
            return false;
        }
        $m->update(['can_vote' => 0]);
        MemberVotingLog::create([
            'member_id'  => $memberId,
            'action'     => MemberVotingLog::ACTION_DEGRADED,
            'meeting_id' => $meetingId,
            'reason'     => $reason,
            'user_id'    => $userId,
            'created_at' => now(),
        ]);
        return true;
    }
}
