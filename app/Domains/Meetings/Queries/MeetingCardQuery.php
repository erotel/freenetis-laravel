<?php

namespace App\Domains\Meetings\Queries;

use App\Domains\Meetings\Repositories\MeetingRepository;
use App\Domains\Meetings\Support\AbsenceStreak;
use App\Models\Meeting;
use Illuminate\Support\Collection;

/** Karta schůze: senioři s docházkou, plné moci, kvórum, kandidáti na degradaci. */
class MeetingCardQuery
{
    public function __construct(private MeetingRepository $meetings, private AbsenceStreak $streak)
    {
    }

    /**
     * Senioři relevantní pro schůzi: uzavřená → snapshot (řádky docházky; nový
     * senior se zpětně neobjeví), jinak aktuální senioři ∪ ti, kdo už mají řádek.
     */
    public function seniors(Meeting $meeting): Collection
    {
        $ids = $meeting->status === Meeting::STATUS_CLOSED
            ? $this->meetings->attendeeIds($meeting->id)
            : $this->meetings->seniorIds()->merge($this->meetings->attendeeIds($meeting->id))->unique();
        return $this->meetings->membersByName($ids);
    }

    public function card(Meeting $meeting): array
    {
        $seniors     = $this->seniors($meeting);
        $attendances = $this->meetings->attendances($meeting->id);
        $proxyLoad   = $this->meetings->proxyLoad($meeting->id);

        $rows = [];
        $present = 0;
        $proxied = 0;
        foreach ($seniors as $s) {
            $att       = $attendances->get($s->id);
            $isPresent = $att && $att->checked_in_at !== null;
            $isProxied = $att && $att->checked_in_at === null && $att->proxy_holder_id !== null;
            if ($isPresent) $present++;
            if ($isProxied) $proxied++;
            $priorAbsences = $this->streak->count($s->id, $meeting->id);   // série z předchozích schůzí
            $rows[] = [
                'member'         => $s,
                'present'        => $isPresent,
                'proxied'        => $isProxied,
                'proxy_holder'   => $att->proxy_holder_id ?? null,
                'checked_in_at'  => $att->checked_in_at ?? null,
                'proxy_load'     => (int) ($proxyLoad[$s->id] ?? 0),
                'prior_absences' => $priorAbsences,
                'at_risk'        => $priorAbsences >= AbsenceStreak::DEGRADE_AFTER - 1,   // 2× → hrozí 3.
            ];
        }

        $total      = count($seniors);
        $counted    = $present + $proxied;
        $quorumNeed = (int) ceil($total * $meeting->quorum_percent / 100);

        // kandidáti na degradaci jen u uzavřené schůze (po snapshotu)
        $degradeCandidates = [];
        if ($meeting->status === Meeting::STATUS_CLOSED) {
            foreach ($seniors as $s) {
                if ($this->streak->count($s->id) >= AbsenceStreak::DEGRADE_AFTER) {
                    $degradeCandidates[] = $s;
                }
            }
        }

        return [
            'meeting'           => $meeting,
            'rows'              => $rows,
            'present'           => $present,
            'proxied'           => $proxied,
            'counted'           => $counted,
            'total'             => $total,
            'quorumNeed'        => $quorumNeed,
            'quorumMet'         => $total > 0 && $counted >= $quorumNeed,
            'degradeCandidates' => $degradeCandidates,
        ];
    }
}
